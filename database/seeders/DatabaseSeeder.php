<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AdmissionSetupSeeder::class,
            SysadminSeeder::class,
        ]);

        // Demo accounts and sample applicants never go to production
        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
