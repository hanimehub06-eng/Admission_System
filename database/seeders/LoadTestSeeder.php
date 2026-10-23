<?php

namespace Database\Seeders;

use App\Models\AdmissionPeriod;
use App\Models\Program;
use App\Models\Requirement;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Local performance testing only. Bulk-inserts applicants at a realistic
 * spread of stages (account, profile, choices, documents, exam, result,
 * history, audit) so screens and endpoints can be measured at peak size.
 *
 *   LOADTEST_APPLICANTS=20000 php artisan db:seed --class=LoadTestSeeder
 *
 * Every sample account's password is "password123". Rows are inserted
 * directly (no files on disk); the service-driven DemoApplicantsSeeder is
 * the one to use for realistic small demos.
 */
class LoadTestSeeder extends Seeder
{
    private const CHUNK = 500;

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->error('LoadTestSeeder runs only in local/testing.');

            return;
        }
        mt_srand(42);
        $n = (int) env('LOADTEST_APPLICANTS', 20000);
        // Continue numbering after an earlier run
        $offset = DB::table('users')->where('email', 'like', 'load%@example.com')->count();
        $period = AdmissionPeriod::current();
        $programs = Program::pluck('id')->all();
        $docs = Requirement::admission()->where('is_uploadable', true)->whereIn('applies_when', ['all', 'grade12'])->pluck('id')->all();
        $roleId = DB::table('roles')->where('name', 'applicant')->value('id');
        $staffId = User::role('admission')->value('id') ?? User::value('id');
        $hash = Hash::make('password123');
        $now = Carbon::now();

        // Sessions left behind by an interrupted run (nobody registered in them)
        DB::table('exam_sessions')->where('venue', 'like', 'Load room %')
            ->whereNotIn('id', DB::table('exam_registrations')->select('exam_session_id'))->delete();

        // Enough exam seats for everyone who gets that far: 30 per batch, past and upcoming days
        $sessions = [];
        $sessionRows = [];
        for ($s = 0; $s < (int) ceil($n * 0.6 / 30) + 2; $s++) { // ~60% of applicants reach the exam
            $day = $now->copy()->addDays(intdiv($s, 20) * 7 - 28);
            $sessionRows[] = ['admission_period_id' => $period->id, 'date' => $day->toDateString(), 'batch' => $s % 2 ? 'B' : 'A',
                'venue' => 'Load room '.$offset.'-'.intdiv($s % 20, 2), 'capacity' => 30, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($sessionRows, self::CHUNK) as $chunk) {
            DB::table('exam_sessions')->insert($chunk);
        }
        // Real ids (auto-increment can skip numbers)
        $sessions = DB::table('exam_sessions')->where('venue', 'like', "Load room {$offset}-%")->orderBy('id')->pluck('id')->all();

        $userId = (int) DB::table('users')->max('id');
        $applicantId = (int) DB::table('applicants')->max('id');
        $applicationId = (int) DB::table('applications')->max('id');
        $seat = 0;

        $this->command?->getOutput()->progressStart($n);
        for ($done = 0; $done < $n; $done += self::CHUNK) {
            $rows = array_fill_keys(['users', 'model_has_roles', 'applicants', 'applications', 'application_program_choices',
                'application_documents', 'exam_registrations', 'exam_results', 'application_status_history', 'audit_logs'], []);

            for ($j = $done; $j < min($n, $done + self::CHUNK); $j++) {
                $i = $offset + $j;
                $uid = ++$userId;
                $aid = ++$applicantId;
                $appId = ++$applicationId;
                $roll = mt_rand(1, 100);
                $status = match (true) {
                    $roll <= 8 => 'draft', $roll <= 33 => 'submitted', $roll <= 40 => 'resubmit',
                    $roll <= 65 => 'for_exam', $roll <= 90 => 'passed', default => 'failed',
                };
                $created = $now->copy()->subDays(mt_rand(1, 60))->subMinutes(mt_rand(0, 1440));
                $submitted = $status === 'draft' ? null : $created->copy()->addHours(mt_rand(1, 72));

                $rows['users'][] = ['id' => $uid, 'name' => "Load Applicant {$i}", 'email' => "load{$i}@example.com", 'password' => $hash,
                    'is_active' => true, 'created_at' => $created, 'updated_at' => $created];
                $rows['model_has_roles'][] = ['role_id' => $roleId, 'model_type' => User::class, 'model_id' => $uid];
                $rows['applicants'][] = ['id' => $aid, 'user_id' => $uid, 'lrn' => sprintf('9%011d', $i), 'last_name' => 'Applicant', 'first_name' => "Load {$i}",
                    'sex' => $i % 2 ? 'Female' : 'Male', 'birthday' => '2008-05-01', 'civil_status' => 'Single', 'phone' => '+63 917 000 0000',
                    'address' => "{$i} Load St.", 'city' => $i % 5 ? 'Pasig City' : 'Cainta', 'province' => 'Metro Manila', 'is_resident' => (bool) ($i % 5),
                    'guardian_name' => 'Parent', 'guardian_phone' => '+63 918 000 0000', 'last_school' => 'Rizal High School', 'school_type' => 'Public',
                    'strand' => 'STEM', 'grad_year' => '2027 (expected)', 'applicant_type' => 'grade12', 'gwa' => 90, 'created_at' => $created, 'updated_at' => $created];
                $rows['applications'][] = ['id' => $appId, 'number' => sprintf('LT-2026-%06d', $i), 'applicant_id' => $aid, 'admission_period_id' => $period->id,
                    'status' => $status, 'campus' => 'Main Campus', 'form_progress' => json_encode(['step' => 3, 'completed' => [1, 2, 3]]),
                    'submitted_at' => $submitted, 'locked_at' => $submitted, 'created_at' => $created, 'updated_at' => $created];

                $p1 = $programs[array_rand($programs)];
                $p2 = $programs[($p1 + 3) % count($programs)] ?? $programs[0];
                $p2 = $p2 === $p1 ? $programs[($p1 + 4) % count($programs)] : $p2;
                $rows['application_program_choices'][] = ['application_id' => $appId, 'program_id' => $p1, 'rank' => 1, 'created_at' => $created, 'updated_at' => $created];
                $rows['application_program_choices'][] = ['application_id' => $appId, 'program_id' => $p2, 'rank' => 2, 'created_at' => $created, 'updated_at' => $created];

                foreach ($docs as $k => $reqId) {
                    $docStatus = match ($status) {
                        'draft', 'submitted' => 'pending',
                        'resubmit' => $k === 0 ? 'resubmit' : 'verified',
                        default => 'verified',
                    };
                    $rows['application_documents'][] = ['application_id' => $appId, 'requirement_id' => $reqId, 'version' => 1, 'is_current' => true,
                        'status' => $docStatus, 'storage_driver' => 'local', 'storage_ref' => "documents/load/{$i}_{$k}.pdf", 'original_name' => "doc{$k}.pdf",
                        'mime' => 'application/pdf', 'size' => 250000, 'remark' => $docStatus === 'resubmit' ? 'Image is blurry.' : null,
                        'uploaded_by' => $uid, 'reviewed_by' => $docStatus === 'pending' ? null : $staffId,
                        'reviewed_at' => $docStatus === 'pending' ? null : $submitted, 'created_at' => $created, 'updated_at' => $created];
                }

                $history = [[null, 'draft', $created]];
                $audit = [['Applicant', 'Created an applicant account', $created]];
                if ($submitted) {
                    $history[] = ['draft', 'submitted', $submitted];
                    $audit[] = ['Applicant', 'Submitted the application form and '.count($docs).' documents', $submitted];
                }
                if (in_array($status, ['for_exam', 'passed', 'failed'], true)) {
                    $session = $sessions[intdiv($seat++, 30)];
                    $rows['exam_registrations'][] = ['application_id' => $appId, 'exam_session_id' => $session, 'permit_no' => sprintf('LX-27-%06d', $i),
                        'permit_checked' => $status !== 'for_exam', 'form_checked' => $status !== 'for_exam', 'attendance' => $status === 'for_exam' ? null : 'present',
                        'approved_by' => $staffId, 'created_at' => $submitted, 'updated_at' => $submitted];
                    $history[] = ['submitted', 'for_exam', $submitted->copy()->addDays(2)];
                    $audit[] = ['Maria Santos', 'Approved for the entrance exam', $submitted->copy()->addDays(2)];
                }
                if (in_array($status, ['passed', 'failed'], true)) {
                    $released = $i % 2 === 0;
                    $rows['exam_results'][] = ['application_id' => $appId, 'score' => $status === 'passed' ? mt_rand(60, 98) : mt_rand(30, 59), 'decision' => $status,
                        'recorded_by' => $staffId, 'recorded_at' => $submitted->copy()->addDays(9), 'is_released' => $released,
                        'released_by' => $released ? $staffId : null, 'released_at' => $released ? $submitted->copy()->addDays(10) : null,
                        'created_at' => $submitted, 'updated_at' => $submitted];
                    $history[] = ['for_exam', $status, $submitted->copy()->addDays(9)];
                    $audit[] = ['Maria Santos', 'Recorded exam result: '.($status === 'passed' ? 'Passed' : 'Did not pass'), $submitted->copy()->addDays(9)];
                }
                foreach ($history as [$from, $to, $at]) {
                    $rows['application_status_history'][] = ['application_id' => $appId, 'from_status' => $from, 'to_status' => $to, 'created_at' => $at];
                }
                foreach ($audit as [$actor, $text, $at]) {
                    $rows['audit_logs'][] = ['user_id' => $actor === 'Applicant' ? $uid : $staffId, 'actor_name' => $actor, 'action' => 'loadtest',
                        'application_id' => $appId, 'description' => $text, 'created_at' => $at];
                }
            }

            DB::transaction(function () use ($rows) {
                foreach ($rows as $table => $data) {
                    foreach (array_chunk($data, self::CHUNK) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                }
            });
            $this->command?->getOutput()->progressAdvance(min(self::CHUNK, $n - $done));
        }
        $this->command?->getOutput()->progressFinish();
    }
}
