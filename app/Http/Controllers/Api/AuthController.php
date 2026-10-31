<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Notifications\VerifyApplicantEmail;
use App\Services\ApplicationService;
use App\Services\AuditLogger;
use App\Services\Security\CaptchaVerifier;
use App\Services\TwoFactorService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;

/**
 * JWT authentication for both portals (NFR-04). Every endpoint answers in
 * the { data } / { message, errors } shape the rest of the API uses.
 *
 * Applicants may send the device_token they got after an email code (body
 * or X-Device-Token header) to skip the code on that device.
 */
class AuthController extends Controller
{
    public function __construct(
        private TwoFactorService $twoFactor,
        private AuditLogger $audit,
    ) {}

    public function register(RegisterRequest $request, ApplicationService $applications): JsonResponse
    {
        $user = $applications->register($request->validated());

        // Applicant 2FA: no session until the emailed code proves the address
        if (config('admission.applicant_email_2fa')) {
            return response()->json(['data' => [
                'two_factor_required' => true,
                'method' => 'email',
                'challenge' => $this->twoFactor->challenge($user, TwoFactorService::REGISTRATION),
            ]], 201);
        }

        return $this->tokenResponse($user, 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        // FR-02: locked for 15 minutes after 5 failed sign-ins in a row
        if ($user?->locked_until?->isFuture()) {
            throw ValidationException::withMessages(['email' => $this->lockedMessage($user)]);
        }
        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            if ($user) {
                $this->recordFailedLogin($user);
            }
            throw ValidationException::withMessages(['email' => $user?->locked_until?->isFuture()
                ? $this->lockedMessage($user)
                : "The email or password doesn't match our records."]);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'This account is disabled. Contact the admission office.']);
        }
        if (! $this->allowedOnPortal($user, $request->string('portal'))) {
            $hint = match ($user->primaryRole()) {
                Role::Applicant => ' Applicants sign in at the applicant portal.',
                Role::Admission, Role::AdmissionHead, Role::CollegeDean, Role::Proctor, Role::ReadOnly, Role::SuperAdmin, Role::Clinic => ' School employees sign in at the employee portal (/admin).',
                default => '',
            };
            throw ValidationException::withMessages(['email' => "This account can't sign in here.".$hint]);
        }
        if ($this->twoFactor->mustEnrollFirst($user)) {
            throw ValidationException::withMessages(['email' => 'Two-factor sign-in must be set up for this account first. Ask the system administrator.']);
        }

        $device = $request->input('device_token') ?: $request->header('X-Device-Token');
        if ($this->twoFactor->required($user, $device ? (string) $device : null)) {
            return response()->json(['data' => [
                'two_factor_required' => true,
                'method' => $this->twoFactor->method($user),
                'challenge' => $this->twoFactor->challenge($user),
            ]]);
        }

        return $this->completeLogin($user);
    }

    /** Second step of login or sign-up (the applicant portal's code screen). */
    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
        ]);

        $user = $this->twoFactor->verify($data['challenge'], $data['code'], [TwoFactorService::LOGIN, TwoFactorService::REGISTRATION]);
        if (! $user) {
            throw ValidationException::withMessages(['code' => 'The code is wrong or has expired.']);
        }

        // An applicant's code came by email, so it also confirms the address (FR-01)
        if ($user->hasRole('applicant') && $this->twoFactor->method($user) === 'email' && ! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $response = $this->completeLogin($user, twoFactor: true);
        if ($user->hasRole('applicant')) {
            // This device skips the email code next time
            $payload = $response->getData(true);
            $payload['data']['device_token'] = $this->twoFactor->trustDevice($user, $request->userAgent());
            $response->setData($payload);
        }

        return $response;
    }

    /** "Resend code" on the code screen: a new email code for the same challenge. */
    public function resendTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string']]);
        if ($error = $this->twoFactor->resend($data['challenge'])) {
            throw ValidationException::withMessages(['code' => $error]);
        }

        return response()->json(['message' => 'We sent you a new code.']);
    }

    /** FR-01: send the verification link again. */
    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Your email address is already confirmed.']);
        }
        $user->notify(new VerifyApplicantEmail);

        return response()->json(['message' => "We sent a new confirmation link to {$user->email}."]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user())]);
    }

    /**
     * NFR-04: a new 15-minute access token for the current one, even if it
     * has just expired (within JWT_REFRESH_TTL). The old token is revoked,
     * so each token can be refreshed once (rotation).
     */
    public function refresh(): JsonResponse
    {
        try {
            $token = auth('api')->refresh();
        } catch (JWTException) {
            return response()->json(['message' => 'Your session has ended. Sign in again.'], 401);
        }
        $user = auth('api')->setToken($token)->user();
        if (! $user || ! $user->is_active) {
            auth('api')->invalidate();

            return response()->json(['message' => 'Your session has ended. Sign in again.'], 401);
        }

        return $this->tokenPayload($token, $user);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->primaryRole()?->isStaff()) {
            $this->audit->record('auth.logout', 'Signed out of the admission office panel');
        }
        auth('api')->logout();

        return response()->json(['message' => "You've been signed out."]);
    }

    /** Same answer whether or not the account exists (no account enumeration). */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'captcha' => CaptchaVerifier::rule()]);
        Password::sendResetLink(['email' => $data['email']]);

        return response()->json(['message' => "If an account exists for {$data['email']}, a reset link is on its way."]);
    }

    /** Called by the portal's "new password" form; also lifts a sign-in lockout. */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'failed_logins' => 0, 'locked_until' => null])->save();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => 'Your password has been changed. You can sign in now.']);
    }

    private function recordFailedLogin(User $user): void
    {
        $max = (int) config('admission.lockout.attempts');
        $failed = $user->failed_logins + 1;
        if ($failed < $max) {
            $user->forceFill(['failed_logins' => $failed])->save();

            return;
        }
        $minutes = (int) config('admission.lockout.minutes');
        $user->forceFill(['failed_logins' => 0, 'locked_until' => now()->addMinutes($minutes)])->save();
        $this->audit->record('auth.locked', "Account locked for {$minutes} minutes after {$max} failed sign-ins", $user, actorName: 'System');
    }

    private function lockedMessage(User $user): string
    {
        $minutes = max(1, (int) ceil(now()->diffInSeconds($user->locked_until) / 60));

        return "Too many failed sign-ins. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').', or reset your password.';
    }

    private function completeLogin(User $user, bool $twoFactor = false): JsonResponse
    {
        $user->forceFill(['last_login_at' => now(), 'failed_logins' => 0, 'locked_until' => null])->save();
        // FR-15: every sign-in is audited
        $user->primaryRole()?->isStaff()
            ? $this->audit->record('auth.login', 'Signed in to the admission office panel'.($twoFactor ? ' (two-factor)' : ''), actor: $user)
            : $this->audit->record('applicant.login', 'Signed in to the applicant portal'.($twoFactor ? ' (email code)' : ''), $user, actor: $user);

        return $this->tokenPayload(auth('api')->claims(['tfa' => $twoFactor])->login($user), $user);
    }

    private function allowedOnPortal(User $user, string $portal): bool
    {
        // Two sign-in screens (NFR-01): applicants, and school employees ('office', every staff role, the clinic nurse included)
        return in_array($user->primaryRole(), match ($portal) {
            'applicant' => [Role::Applicant],
            'office' => [Role::Admission, Role::AdmissionHead, Role::CollegeDean, Role::Proctor, Role::ReadOnly, Role::SuperAdmin, Role::Clinic],
        }, true);
    }

    private function tokenResponse(User $user, int $status = 200): JsonResponse
    {
        return $this->tokenPayload(auth('api')->claims(['tfa' => false])->login($user), $user, $status);
    }

    private function tokenPayload(string $token, User $user, int $status = 200): JsonResponse
    {
        return response()->json(['data' => [
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => $this->userPayload($user),
        ]], $status);
    }

    private function userPayload(User $user): array
    {
        $role = $user->primaryRole();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'initials' => $user->initials(),
            'email' => $user->email,
            'role' => $role?->value,
            'role_label' => $role?->label(),
            'two_factor_enabled' => $user->hasTwoFactor(),
            'email_verified' => $user->hasVerifiedEmail(),
        ];
    }
}
