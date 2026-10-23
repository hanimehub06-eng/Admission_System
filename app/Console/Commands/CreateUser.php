<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\College;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Sysadmin only (§7.1): staff accounts are never created through the API. */
class CreateUser extends Command
{
    protected $signature = 'user:create {email} {name} {role : admission|college_dean|clinic|sysadmin}
        {--college= : College code, for a college_dean}';

    protected $description = 'Create a staff or sysadmin account and print a temporary password';

    public function handle(AuditLogger $audit): int
    {
        $role = Role::tryFrom($this->argument('role'));
        if (! $role || $role === Role::Applicant) {
            $this->error('Role must be admission, college_dean, clinic or sysadmin. Applicants register in the portal.');

            return self::FAILURE;
        }
        if (User::withTrashed()->where('email', $this->argument('email'))->exists()) {
            $this->error('That email already has an account.');

            return self::FAILURE;
        }
        $college = null;
        if ($role === Role::CollegeDean) {
            $college = College::where('code', strtoupper((string) $this->option('college')))->first();
            if (! $college) {
                $this->error('A college_dean needs --college=CODE. Colleges: '.College::orderBy('code')->pluck('code')->implode(', '));

                return self::FAILURE;
            }
        }

        $password = Str::password(16);
        $user = User::create(['name' => $this->argument('name'), 'email' => $this->argument('email'), 'password' => $password]);
        $user->assignRole($role->value);
        $college?->update(['dean_user_id' => $user->id]);
        $audit->record('account.created', "Created {$role->label()} account for {$user->email}".($college ? " ({$college->code})" : ''), $user);

        $this->info("Created {$role->label()} account {$user->email}".($college ? " for {$college->name}" : '').'.');
        $this->line("Temporary password: {$password}");
        if (config('admission.staff_2fa_required')) {
            $this->line("Two-factor sign-in is required: run  php artisan user:2fa {$user->email} totp");
        }

        return self::SUCCESS;
    }
}
