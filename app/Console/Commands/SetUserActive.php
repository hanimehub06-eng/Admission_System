<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

class SetUserActive extends Command
{
    protected $signature = 'user:active {email} {state : on|off}';

    protected $description = 'Enable or disable an account (takes effect on the next request)';

    public function handle(AuditLogger $audit): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user || ! in_array($this->argument('state'), ['on', 'off'], true)) {
            $this->error('Unknown account, or state is not on/off.');

            return self::FAILURE;
        }

        $active = $this->argument('state') === 'on';
        $user->forceFill(['is_active' => $active])->save();
        $audit->record($active ? 'account.enabled' : 'account.disabled', ($active ? 'Enabled' : 'Disabled')." account {$user->email}", $user);
        $this->info(($active ? 'Enabled ' : 'Disabled ').$user->email);

        return self::SUCCESS;
    }
}
