<?php

namespace App\Services;

use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\TwoFactorCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-factor checks (NFR-04, decision T4):
 * - staff (personnel, dean, clinic, sysadmin): 6-digit code from an
 *   authenticator app at every sign-in;
 * - applicants: 6-digit code by email when creating the account (it also
 *   confirms the address), on a new device (the device is then trusted for
 *   TRUSTED_DEVICE_DAYS) and again when accepting a slot.
 *
 * A challenge is a short-lived token bound to one purpose; the client
 * trades challenge + code for what it asked for.
 */
class TwoFactorService
{
    public const LOGIN = 'login';

    public const SLOT_ACCEPTANCE = 'slot_acceptance';

    /** A new applicant account: the code also confirms the email address. */
    public const REGISTRATION = 'registration';

    private const TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    private const MAX_RESENDS = 3;

    private const RESEND_AFTER_SECONDS = 30;

    public function __construct(private Google2FA $google2fa) {}

    /** Whether sign-in needs a code. Applicants skip it on a trusted device. */
    public function required(User $user, ?string $deviceToken = null): bool
    {
        if ($user->hasRole('applicant')) {
            $on = $user->hasTwoFactor() || config('admission.applicant_email_2fa');

            return $on && ! $this->isTrusted($user, $deviceToken);
        }

        return $user->hasTwoFactor();
    }

    /** Staff who must use 2FA but haven't been enrolled can't sign in (STAFF_2FA_REQUIRED). */
    public function mustEnrollFirst(User $user): bool
    {
        return config('admission.staff_2fa_required') && ! $user->hasRole('applicant') && ! $user->hasTwoFactor();
    }

    /** Slot acceptance needs an email code when APPLICANT_ACCEPTANCE_2FA is on. */
    public function requiredForAcceptance(User $user): bool
    {
        return (bool) config('admission.applicant_acceptance_2fa');
    }

    public function method(User $user): string
    {
        return $user->two_factor_method ?? $user->primaryRole()?->twoFactorMethod() ?? 'email';
    }

    /** Start a challenge; for email 2FA this also sends the code. */
    public function challenge(User $user, string $purpose = self::LOGIN): string
    {
        $token = Str::random(64);
        $entry = ['user_id' => $user->id, 'purpose' => $purpose, 'attempts' => 0, 'code' => null, 'resends' => 0, 'sent_at' => now()->timestamp];

        // Slot acceptance and new accounts are always confirmed by email: it's the applicant's own inbox that matters
        if (in_array($purpose, [self::SLOT_ACCEPTANCE, self::REGISTRATION], true) || $this->method($user) === 'email') {
            $entry['code'] = $this->sendCode($user, $purpose);
        }

        Cache::put($this->key($token), $entry, now()->addMinutes(self::TTL_MINUTES));

        return $token;
    }

    /**
     * Email a fresh code for an open challenge ("Resend code"). The old code
     * stops working. Returns null when it went out, or why it didn't.
     */
    public function resend(string $token): ?string
    {
        $entry = Cache::get($this->key($token));
        if (! $entry || ! $entry['code'] || $entry['attempts'] >= self::MAX_ATTEMPTS) {
            return 'This code has expired. Start again.';
        }
        if (($entry['resends'] ?? 0) >= self::MAX_RESENDS) {
            return "We can't send more codes for this attempt. Start again.";
        }
        $wait = ($entry['sent_at'] ?? 0) + self::RESEND_AFTER_SECONDS - now()->timestamp;
        if ($wait > 0) {
            return "Wait {$wait} seconds before asking for a new code.";
        }
        $user = User::find($entry['user_id']) ?? abort(404);

        $entry['code'] = $this->sendCode($user, $entry['purpose']);
        $entry['resends'] = ($entry['resends'] ?? 0) + 1;
        $entry['sent_at'] = now()->timestamp;
        Cache::put($this->key($token), $entry, now()->addMinutes(self::TTL_MINUTES));

        return null;
    }

    /**
     * Returns the user when the code is right for that purpose, null otherwise.
     * $purpose may list several purposes the caller accepts.
     */
    public function verify(string $token, string $code, string|array $purpose = self::LOGIN): ?User
    {
        $entry = Cache::get($this->key($token));
        if (! $entry || $entry['attempts'] >= self::MAX_ATTEMPTS || ! in_array($entry['purpose'] ?? self::LOGIN, (array) $purpose, true)) {
            return null;
        }

        $user = User::find($entry['user_id']);
        $ok = $user && ($entry['code']
            ? Hash::check($code, $entry['code'])
            : $this->google2fa->verifyKey((string) $user->two_factor_secret, $code));

        if (! $ok) {
            $entry['attempts']++;
            Cache::put($this->key($token), $entry, now()->addMinutes(self::TTL_MINUTES));

            return null;
        }

        Cache::forget($this->key($token));

        return $user;
    }

    /** Remember this device for an applicant; returns the token the client keeps. */
    public function trustDevice(User $user, ?string $label = null): string
    {
        $token = Str::random(64);
        TrustedDevice::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'label' => $label ? mb_substr($label, 0, 255) : null,
            'last_used_at' => now(),
            'expires_at' => now()->addDays(config('admission.trusted_device_days')),
        ]);

        return $token;
    }

    public function isTrusted(User $user, ?string $deviceToken): bool
    {
        if (! $deviceToken) {
            return false;
        }
        $device = TrustedDevice::where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $deviceToken))
            ->where('expires_at', '>', now())->first();
        $device?->update(['last_used_at' => now()]);

        return $device !== null;
    }

    /** Enrol a staff member in authenticator-app 2FA (artisan, no UI yet). */
    public function enableTotp(User $user): string
    {
        $secret = $this->google2fa->generateSecretKey();
        $user->forceFill([
            'two_factor_method' => 'totp',
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $this->google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret);
    }

    public function enableEmail(User $user): void
    {
        $user->forceFill([
            'two_factor_method' => 'email',
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_method' => null, 'two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        TrustedDevice::where('user_id', $user->id)->delete();
    }

    /** Email a new 6-digit code; returns its hash for the challenge. */
    private function sendCode(User $user, string $purpose): string
    {
        $code = (string) random_int(100000, 999999);
        $user->notify(new TwoFactorCode($code, self::TTL_MINUTES, $purpose));

        return Hash::make($code);
    }

    private function key(string $token): string
    {
        return '2fa:'.hash('sha256', $token);
    }
}
