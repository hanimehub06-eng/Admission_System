<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TwoFactorService;
use Illuminate\Console\Command;

/**
 * 2FA enrolment until the UI has a setup screen (decision T4). For "totp"
 * it prints the otpauth:// link to load into an authenticator app.
 */
class SetUserTwoFactor extends Command
{
    protected $signature = 'user:2fa {email} {method : totp|email|off}';

    protected $description = 'Turn two-factor sign-in on (authenticator app or email code) or off for an account';

    public function handle(TwoFactorService $twoFactor, AuditLogger $audit): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user || ! in_array($this->argument('method'), ['totp', 'email', 'off'], true)) {
            $this->error('Unknown account, or method is not totp, email or off.');

            return self::FAILURE;
        }

        match ($this->argument('method')) {
            'totp' => $this->line('Add this to the authenticator app: '.$twoFactor->enableTotp($user)),
            'email' => $twoFactor->enableEmail($user),
            'off' => $twoFactor->disable($user),
        };

        $audit->record('account.2fa', "Set two-factor sign-in to \"{$this->argument('method')}\" for {$user->email}", $user);
        $this->info('Done.');

        return self::SUCCESS;
    }
}
