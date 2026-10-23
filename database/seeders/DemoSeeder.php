<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\College;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local only: staff logins plus sample applicants and exam sessions so the
 * panels have something to show. Password for every demo account:
 * "password123".
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $staff = [
            ['Maria Santos', 'admissions@plpasig.edu.ph', Role::Admission],
            ['R. Dizon', 'r.dizon@plpasig.edu.ph', Role::Admission],
            ['Dr. L. Bautista', 'clinic@plpasig.edu.ph', Role::Clinic],
            ['Dean A. Villanueva', 'dean.con@plpasig.edu.ph', Role::CollegeDean],
            ['Dean P. Lim', 'dean.ccs@plpasig.edu.ph', Role::CollegeDean],
        ];
        foreach ($staff as [$name, $email, $role]) {
            $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'password123']);
            $user->syncRoles([$role->value]);
        }
        College::where('code', 'CON')->update(['dean_user_id' => User::where('email', 'dean.con@plpasig.edu.ph')->value('id')]);
        College::where('code', 'CCS')->update(['dean_user_id' => User::where('email', 'dean.ccs@plpasig.edu.ph')->value('id')]);

        $this->call(DemoApplicantsSeeder::class);
    }
}
