<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\College;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Seeder;

/**
 * Demo staff accounts, on every server, until EMS is connected (real
 * staff accounts are then linked by employee no.; see ems:sync-staff). Decided by the project owner on 2026-10-24, so
 * the portals can be used before that integration exists. Running it again
 * resets these passwords and re-enables the accounts.
 *
 * Remove them when the Employment System sends real accounts:
 *   php artisan user:active <email> off
 */
class DemoStaffAccountsSeeder extends Seeder
{
    public const ACCOUNTS = [
        // email, name, role, password, task (Admission Personnel only, AccountController::TASKS)
        ['abella_gabrielrey@plpasig.edu.ph', 'Gabriel Rey Abella', Role::AdmissionHead, 'admin123', null],
        ['ignacio_keane@plpasig.edu.ph', 'Keane Ignacio', Role::Clinic, 'clinic123', null],
        ['personnel@plpasig.edu.ph', 'Demo Admission Personnel', Role::Admission, 'password123', 'personnel'],
        ['proctor@plpasig.edu.ph', 'Demo Exam Proctor', Role::Admission, 'password123', 'proctor'],
        ['examoffice@plpasig.edu.ph', 'Demo Exam Office', Role::Admission, 'password123', 'exam_office'],
    ];

    /** One demo College Dean per college: dean.<code>@plpasig.edu.ph, who sees only that college's applicants. */
    public const DEAN_PASSWORD = 'dean1234';

    public static function deanEmail(string $collegeCode): string
    {
        return 'dean.'.strtolower($collegeCode).'@plpasig.edu.ph';
    }

    public function run(AuditLogger $audit): void
    {
        $accounts = array_map(fn ($a) => [...$a, null], self::ACCOUNTS);
        foreach (College::orderBy('code')->get() as $college) {
            $accounts[] = [self::deanEmail($college->code), "Dean, {$college->name}", Role::CollegeDean, self::DEAN_PASSWORD, null, $college];
        }

        foreach ($accounts as [$email, $name, $role, $password, $task, $college]) {
            $user = User::withTrashed()->firstOrNew(['email' => $email]);
            $created = ! $user->exists;
            $user->trashed() && $user->restore();
            $user->forceFill([
                'name' => $name,
                'password' => $password,
                'is_active' => true,
                'failed_logins' => 0,
                'locked_until' => null,
                'source' => 'demo',
                'task' => $task,
            ])->save();
            $user->syncRoles([$role->value]);
            $college?->update(['dean_user_id' => $user->id]);
            $audit->record($created ? 'account.created' : 'account.updated',
                ($created ? 'Created' : 'Reset').' demo '.$role->label()." account {$email}".($college ? " ({$college->code})" : '').' (until the Employment System is connected)', $user, actorName: 'System');
        }
    }
}
