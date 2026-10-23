<?php

namespace Database\Seeders;

use App\Models\AdmissionPeriod;
use App\Models\Application;
use App\Models\ExamSession;
use App\Models\InterviewSchedule;
use App\Models\Program;
use App\Models\Requirement;
use App\Models\User;
use App\Services\ApplicationService;
use App\Services\DocumentService;
use App\Services\ExamService;
use App\Services\InterviewService;
use App\Services\OfferService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

/**
 * Local/testing only. Walks sample applicants through the real services
 * at back-dated times, so statuses, histories and the audit log are the
 * same as real use would produce. Deterministic (fixed seed).
 */
class DemoApplicantsSeeder extends Seeder
{
    private const FIRST = ['Andrea', 'Bea', 'Camille', 'Danica', 'Erika', 'Faith', 'Gianna', 'Hannah', 'Isabel', 'Janine', 'Aaron', 'Bryan', 'Carlo', 'Daniel', 'Elijah', 'Francis', 'Gabriel', 'Harvey', 'Ivan', 'Jerome', 'Kenneth', 'Lance', 'Mikaela', 'Nicole'];

    private const LAST = ['Abad', 'Aquino', 'Bautista', 'Castillo', 'Cruz', 'Dela Cruz', 'Domingo', 'Flores', 'Garcia', 'Gonzales', 'Lim', 'Lopez', 'Manalo', 'Mendoza', 'Navarro', 'Ocampo', 'Pascual', 'Ramos', 'Reyes', 'Rivera', 'Santos', 'Soriano', 'Tan', 'Torres', 'Valdez', 'Villanueva'];

    private const SCHOOLS = [['Rizal High School', 'Public'], ['Pasig City Science High School', 'Public'], ['Santa Lucia High School', 'Public'], ['Pasig Catholic College', 'Private'], ['Arellano University – Pasig', 'Private'], ['Saint Paul College Pasig', 'Private']];

    private const CITIES = ['Pasig City', 'Pasig City', 'Pasig City', 'Pasig City', 'Cainta', 'Taytay', 'Marikina City'];

    private const STRANDS = ['STEM', 'ABM', 'HUMSS', 'GAS', 'TVL – ICT', 'Arts and Design'];

    private const REMARKS = ['Image is blurry or unreadable. Upload a clearer scan.', 'Wrong document uploaded.', 'Document is incomplete. Include every page.', 'Photo needs a white background and a nameplate.'];

    private ApplicationService $applications;

    private DocumentService $documents;

    private ExamService $exams;

    private Carbon $realNow;

    public function run(): void
    {
        if (Application::exists()) {
            return;
        }
        $this->realNow = Carbon::now();
        // Files left by an earlier demo seed belong to applications that no longer exist
        Storage::disk(config('admission.documents.local_disk'))->deleteDirectory('documents');
        mt_srand(20262027);
        $this->applications = app(ApplicationService::class);
        $this->documents = app(DocumentService::class);
        $this->exams = app(ExamService::class);

        $officer = User::where('email', 'admissions@plpasig.edu.ph')->firstOrFail();
        $officer2 = User::where('email', 'r.dizon@plpasig.edu.ph')->firstOrFail();
        $clinic = User::where('email', 'clinic@plpasig.edu.ph')->firstOrFail();
        $today = Carbon::today();

        // Exam sessions like the panel's demo: two past days, today, two upcoming
        $period = AdmissionPeriod::current();
        $rooms = ['Main Building · Room 301', 'Main Building · Room 302', 'Annex · AVR'];
        $sessions = [];
        foreach ([-13, -6, 0, 9, 16] as $i => $offset) {
            foreach (['A', 'B'] as $j => $batch) {
                $sessions[] = ExamSession::firstOrCreate(
                    ['date' => $today->copy()->addDays($offset)->toDateString(), 'batch' => $batch, 'venue' => $rooms[($i + $j) % 3]],
                    ['admission_period_id' => $period->id, 'capacity' => 30, 'created_by' => $officer->id],
                );
            }
        }
        $programs = Program::pluck('name')->all();

        for ($i = 0; $i < 60; $i++) {
            $age = mt_rand(0, 40);                       // days since submission
            $stage = $this->pick([
                'draft' => 6, 'queue' => 14, 'resubmit' => 6, 'verified' => 8, 'scheduled' => 8, 'examined' => 18,
            ]);
            if (in_array($stage, ['scheduled', 'examined'], true)) {
                $age = max($age, $stage === 'examined' ? 20 : 8);
            }
            $submitted = $today->copy()->subDays($age)->setTime(mt_rand(8, 21), mt_rand(0, 59));

            // Account + form
            $this->at($submitted->copy()->subDays(mt_rand(1, 4)));
            $first = self::FIRST[$i % count(self::FIRST)];
            $last = self::LAST[mt_rand(0, count(self::LAST) - 1)];
            $user = $this->applications->register([
                'first_name' => $first, 'last_name' => $last, 'email' => strtolower(str_replace(' ', '', "{$first}.{$last}")).$i.'@example.com',
                'phone' => '+63 9'.mt_rand(10, 99).' '.mt_rand(100, 999).' '.mt_rand(1000, 9999), 'password' => 'password123',
            ]);
            $user->markEmailAsVerified();
            $application = $this->applications->current($user->applicant);
            [$school, $type] = self::SCHOOLS[mt_rand(0, count(self::SCHOOLS) - 1)];
            $choices = $this->sample($programs, 2);
            $this->applications->saveForm($application, [
                'firstName' => $first, 'lastName' => $last, 'middleName' => self::LAST[mt_rand(0, count(self::LAST) - 1)],
                'phone' => $user->applicant->phone, 'birthDate' => (2007 + mt_rand(0, 1)).'-'.sprintf('%02d-%02d', mt_rand(1, 12), mt_rand(1, 28)),
                'sex' => mt_rand(0, 1) ? 'Female' : 'Male', 'civilStatus' => 'Single', 'address' => mt_rand(1, 199).' Mabini St.',
                'city' => self::CITIES[mt_rand(0, count(self::CITIES) - 1)], 'province' => 'Metro Manila', 'zip' => '1600',
                'guardianName' => 'Parent '.$last, 'guardianPhone' => '+63 918 765 4321', 'lastSchool' => $school, 'schoolType' => $type,
                'strand' => self::STRANDS[mt_rand(0, count(self::STRANDS) - 1)], 'gradYear' => mt_rand(0, 9) < 7 ? '2027 (expected)' : '2026',
                'gwa' => (string) (mt_rand(8000, 9800) / 100), 'lrn' => (string) (100000000000 + $i * 7919),
                'program1' => $choices[0], 'program2' => $choices[1], 'campus' => 'Main Campus', 'step' => 3, 'completed' => [1, 2, 3],
            ], $user);

            $required = $this->applications->requiredDocuments($application);
            $uploads = $stage === 'draft' ? $required->random(mt_rand(1, $required->count())) : $required;
            foreach ($uploads as $requirement) {
                $this->documents->upload($application, $requirement, $this->file($requirement->key), $user);
            }
            if ($stage === 'draft') {
                continue;
            }

            $this->at($submitted, $user);
            $this->applications->submit($application->fresh(), $user);
            if ($stage === 'queue') {
                continue;
            }

            // Review a few days later
            $reviewer = mt_rand(0, 1) ? $officer : $officer2;
            $this->at($submitted->copy()->addDays(mt_rand(1, 3))->setTime(mt_rand(8, 16), mt_rand(0, 59)), $reviewer);
            $application = $application->fresh();
            if ($stage === 'resubmit') {
                $bad = $required->random();
                $this->documents->review($application, $bad, 'resubmit', self::REMARKS[mt_rand(0, 3)], $reviewer);
            }
            $this->documents->verifyPending($application->fresh(), 'admission', $reviewer);
            if (in_array($stage, ['resubmit', 'verified'], true)) {
                continue;
            }

            // Schedule: past sessions for examinees, upcoming ones otherwise
            $pool = array_values(array_filter($sessions, fn ($s) => $stage === 'examined' ? $s->date->lt($today) : $s->date->gte($today)));
            $session = $pool[mt_rand(0, count($pool) - 1)];
            if ($session->registrations()->count() >= $session->capacity) {
                continue;
            }
            $this->exams->approve($application->fresh(), $session, $reviewer);
            if ($stage === 'scheduled') {
                continue;
            }

            // Exam day: attendance, then results two days later
            $this->at($session->date->copy()->setTime($session->batch === 'A' ? 12 : 17, 10), $officer2);
            $present = mt_rand(1, 100) <= 92;
            $this->exams->updateRoster($application->fresh(), ['permitChecked' => $present, 'formChecked' => $present, 'attendance' => $present ? 'present' : 'absent'], $officer2);
            if (! $present) {
                continue;
            }
            $this->at($session->date->copy()->addDays(2)->setTime(10, mt_rand(0, 59)), $officer);
            $score = min(98, max(28, (int) round(66 + (mt_rand(0, 100) + mt_rand(0, 100) + mt_rand(0, 100) - 150) / 100 * 26)));
            $this->exams->recordResult($application->fresh(), $score, $score >= config('admission.passing_score') ? 'passed' : 'failed', $officer);

            // The earliest session's results are released; passers get an offer (or an interview first)
            if (! $session->date->isSameDay($sessions[0]->date)) {
                continue;
            }
            $released = $session->date->copy()->addDays(3)->setTime(9, 0);
            $this->at($released, $officer);
            $this->exams->release(collect([$application->fresh()]), $officer);
            if ($score < config('admission.passing_score')) {
                continue;
            }
            $application = $application->fresh();
            if ($application->status->value === 'for_interview') {
                $this->interview($application, $released, $officer);
                $application = $application->fresh();
                if ($application->status->value !== 'offered') {
                    continue;
                }
            }
            if (mt_rand(1, 100) <= 30) {
                continue; // offer not accepted yet
            }
            $this->at($application->offered_at->copy()->addDays(mt_rand(1, 3)), $user);
            app(OfferService::class)->accept($application->fresh(), $user);
            $medical = Requirement::medical()->get();
            foreach ($medical as $requirement) {
                $this->documents->upload($application->fresh(), $requirement, $this->file($requirement->key), $user);
            }
            $this->documents->submitMedical($application->fresh());
            if (mt_rand(1, 100) <= 50) {
                $this->at($released->copy()->addDays(5), $clinic);
                $this->documents->approveMedical($application->fresh(), $clinic);
            }
        }

        Carbon::setTestNow();
        $this->guard()->forgetUser();
    }

    /** The API's JWT guard (typed, so editors know it has setUser/forgetUser). */
    private function guard(): JWTGuard
    {
        /** @var JWTGuard $guard */
        $guard = Auth::guard('api');

        return $guard;
    }

    /** Interview for programs that require one (BSN in the demo): scheduled, then the dean records it. */
    private function interview(Application $application, Carbon $released, User $officer): void
    {
        $program = $application->firstChoice();
        $interviews = app(InterviewService::class);
        $this->at($released->copy()->addHour(), $officer);
        $schedule = InterviewSchedule::where('program_id', $program->id)->first()
            ?? $interviews->createSchedule($program, $released->copy()->addDays(2)->toDateString(), '9:00 AM – 12:00 NN', 'Nursing Building · Room 105', 20, $officer);
        $interviews->assign($application, $schedule, $officer);

        $dean = $program->college?->dean;
        if (! $dean || mt_rand(1, 100) <= 25) {
            return; // outcome not recorded yet
        }
        $this->at($schedule->date->copy()->setTime(14, 0), $dean);
        $grade = mt_rand(70, 98);
        $interviews->record($application->fresh(), $grade, $grade >= 75 ? 'passed' : 'failed', $dean);
    }

    /** Pretend "now" is $time and $user is signed in, so timestamps and audit lines read right. */
    private function at(Carbon $time, ?User $user = null): void
    {
        // Compare with the real clock, not the one frozen by the previous step
        Carbon::setTestNow($time->gt($this->realNow) ? $this->realNow : $time);
        $user ? $this->guard()->setUser($user) : $this->guard()->forgetUser();
    }

    /** A small real PDF or PNG, so files open in the panel. */
    private function file(string $key): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'demo');
        if ($key === 'photo') {
            file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

            return new UploadedFile($path, 'passport_photo.png', 'image/png', null, true);
        }
        $text = "Sample {$key} document (demo data)";
        $stream = "BT /F1 18 Tf 72 720 Td ({$text}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $n => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($n + 1)." 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }
        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        file_put_contents($path, $pdf);

        return new UploadedFile($path, "{$key}.pdf", 'application/pdf', null, true);
    }

    private function pick(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            if (($roll -= $weight) <= 0) {
                return $key;
            }
        }

        return array_key_first($weights);
    }

    private function sample(array $items, int $n): array
    {
        $picked = [];
        while (count($picked) < $n) {
            $picked[$items[mt_rand(0, count($items) - 1)]] = true;
        }

        return array_keys($picked);
    }
}
