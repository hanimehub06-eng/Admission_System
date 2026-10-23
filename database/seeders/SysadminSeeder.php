<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The system administrator account (decision D7). Staff cannot manage
 * accounts; the sysadmin does it with the artisan commands in app/Console.
 */
class SysadminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SYSADMIN_EMAIL', 'sysadmin@plpasig.edu.ph');
        if (User::where('email', $email)->exists()) {
            return;
        }

        $password = env('SYSADMIN_PASSWORD') ?: Str::password(20);
        $user = User::create(['name' => 'System administrator', 'email' => $email, 'password' => $password]);
        $user->assignRole(Role::Sysadmin->value);

        if (! env('SYSADMIN_PASSWORD')) {
            $this->command?->warn("Sysadmin {$email} created with password: {$password}");
        }
    }
}
