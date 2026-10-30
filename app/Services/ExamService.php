<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Events\ExamScheduled;
use App\Events\ResultReleased;
use App\Exceptions\BusinessRuleException;
use App\Models\AdmissionPeriod;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ExamRegistration;
use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\User;
use App\Notifications\AdmissionNotice;
use App\Services\Dms\DmsSync;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pen-and-paper entrance exam (FR-06, FR-07, decision D1) and results
 * (FR-08). Nothing is taken or scored online: staff schedule, scan the QR
 * permit at the venue and record the score from the marked paper. Scores
 * are Restricted: never sent to applicants.
 */
class ExamService
{
    public function __construct(
        private ApplicationService $applications,
        private ApplicationStatusService $status,
        private AuditLogger $audit,
        private OfferService $offers,
        private DmsSync $dms,
    ) {}

    public function createSession(string $date, string $batch, string $venue, int $capacity, User $staff): ExamSession
    {
        $venue = trim($venue);
        $duplicate = ExamSession::whereDate('date', $date)->where('batch', $batch)
            ->whereRaw('LOWER(venue) = ?', [mb_strtolower($venue)])->exists();
        if ($duplicate) {
            throw new BusinessRuleException('That session already exists.');
        }

        $session = ExamSession::create([
            'admission_period_id' => AdmissionPeriod::current()->id,
            'date' => $date, 'batch' => $batch, 'venue' => $venue, 'capacity' => $capacity, 'created_by' => $staff->id,
        ]);
        $this->audit->record('exam.session_created', "Created exam session {$session->label()} at {$venue} ({$capacity} seats)", $session);

        return $session;
    }

    /** All required documents verified and still waiting for review (admin-store.js canApprove). */
    public function canApprove(Application $application): bool
    {
        if ($application->status !== ApplicationStatus::Submitted) {
            return false;
        }
        $docs = $application->documents()->get()->keyBy('requirement_id');

        return $this->applications->requiredDocuments($application)
            ->every(fn ($r) => ($docs[$r->id]->status ?? null) === ApplicationDocument::VERIFIED);
    }

    /**
     * Approve for the exam: assign a session and issue the permit (FR-05,
     * FR-06). $by is admission personnel, or the applicant when
     * self-scheduling is on (D17).
     */
    public function approve(Application $application, ExamSession $session, User $by): ExamRegistration
    {
        if (! $this->canApprove($application)) {
            throw new BusinessRuleException('Verify every required document before approving for the exam.');
        }

        $registration = DB::transaction(function () use ($application, $session, $by) {
            $this->assertSeat($session);
            $application->update(['applicant_no' => $application->applicant_no ?? $this->nextApplicantNo()]);
            $permit = $this->permitNumber($application);
            $registration = ExamRegistration::create([
                'application_id' => $application->id,
                'exam_session_id' => $session->id,
                'permit_no' => $permit,
                'qr_token' => $this->qrToken($permit, $application),
                'approved_by' => $by->isAdmissionOffice() ? $by->id : null,
            ]);
            $this->status->transition($application, ApplicationStatus::ForExam, $by);
            $this->audit->record('exam.approved', ($by->isAdmissionOffice() ? 'Approved for the entrance exam' : 'Chose an entrance exam session')." · {$session->label()}", $registration, $application);
            $this->dms->record('exam.scheduled', $application, $this->sessionData($session, $permit));

            return $registration;
        });
        ExamScheduled::dispatch($application, $session);

        return $registration;
    }

    /**
     * FR-14: give every applicant who is ready for the exam (all documents
     * verified) a seat in the earliest upcoming session that still has
     * room, Batch A before Batch B, oldest submission first. Never goes over
     * a session's capacity. Returns the numbers assigned and left waiting.
     *
     * @param  list<string>|null  $numbers  limit to these applications
     * @return array{assigned: list<string>, waiting: list<string>}
     */
    public function autoAssign(?array $numbers, User $staff): array
    {
        $query = Application::where('admission_period_id', AdmissionPeriod::current()->id)
            ->where('status', ApplicationStatus::Submitted->value)
            ->whereDoesntHave('examRegistration')
            ->orderBy('submitted_at')->orderBy('id');
        if ($numbers !== null) {
            $query->whereIn('number', $numbers);
        }

        $assigned = [];
        $waiting = [];
        foreach ($query->get() as $application) {
            if (! $this->canApprove($application)) {
                continue;
            }
            $session = $this->nextOpenSession();
            if (! $session) {
                $waiting[] = $application->number;

                continue;
            }
            $this->approve($application, $session, $staff);
            $assigned[] = $application->number;
        }
        if ($assigned) {
            $this->audit->record('exam.auto_assigned', 'Assigned '.count($assigned).' applicant'.(count($assigned) === 1 ? '' : 's').' to exam sessions automatically', meta: ['applications' => $assigned]);
        }

        return ['assigned' => $assigned, 'waiting' => $waiting];
    }

    /** The earliest upcoming session of the current period with a free seat. */
    public function nextOpenSession(): ?ExamSession
    {
        return $this->openSessions()->first();
    }

    /** FR-16: one session's examinees, in permit order, for the printed roster. */
    public function roster(ExamSession $session): array
    {
        $rows = ExamRegistration::where('exam_session_id', $session->id)
            ->with('application.applicant')->orderBy('permit_no')->get()
            ->values()->map(fn (ExamRegistration $r, int $i) => [
                $i + 1, $r->permit_no, $r->application->number,
                trim(mb_strtoupper($r->application->applicant->last_name).', '.$r->application->applicant->first_name.' '.($r->application->applicant->middle_name ?? '')),
                match ($r->attendance) { 'present' => 'Present', 'absent' => 'Absent', default => '' },
                $r->envelope_received_at ? 'Received' : '',
                '',
            ])->all();

        return [
            'title' => 'Exam roster · '.ExamSession::BATCHES[$session->batch]['label'].' · '.$session->date->format('l, F j, Y').' · '.$session->venue,
            'period' => AdmissionPeriod::find($session->admission_period_id)?->label ?? '',
            'columns' => ['#', 'Permit', 'Application no.', 'Name', 'Attendance', 'Envelope', 'Signature'],
            'rows' => $rows,
        ];
    }

    /**
     * FR-19: results from a CSV file. Columns (header row required):
     * application number, score, and optionally decision (passed / failed;
     * when left out, the passing score decides). Each line is recorded like
     * a single result; a bad line is reported and the rest still go in.
     *
     * @return array{recorded: int, errors: array<int, string>}
     */
    public function importResults(string $csv, User $staff): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim(preg_replace('/^\xEF\xBB\xBF/', '', $csv)));
        $header = array_map(fn ($h) => strtolower(preg_replace('/[^a-z]/i', '', $h)), str_getcsv(array_shift($lines) ?? ''));
        $col = function (array $names) use ($header) {
            foreach ($names as $name) {
                $i = array_search($name, $header, true);
                if ($i !== false) {
                    return $i;
                }
            }

            return null;
        };
        $numberCol = $col(['applicationnumber', 'applicationno', 'application', 'number', 'id']);
        $scoreCol = $col(['score']);
        $decisionCol = $col(['decision', 'outcome', 'result']);
        if ($numberCol === null || $scoreCol === null) {
            throw new BusinessRuleException('The CSV needs a header row with "application number" and "score" columns.');
        }

        $recorded = 0;
        $errors = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = str_getcsv($line);
            $lineNo = $i + 2;
            $number = trim($row[$numberCol] ?? '');
            $score = trim($row[$scoreCol] ?? '');
            if (! preg_match('/^\d{1,3}$/', $score) || (int) $score > 100) {
                $errors[$lineNo] = "{$number}: the score must be a whole number from 0 to 100.";

                continue;
            }
            $decision = strtolower(trim($decisionCol === null ? '' : ($row[$decisionCol] ?? '')));
            $decision = match (true) {
                in_array($decision, ['passed', 'pass', 'p'], true) => 'passed',
                in_array($decision, ['failed', 'fail', 'did not pass', 'f'], true) => 'failed',
                $decision === '' => (int) $score >= (int) config('admission.passing_score') ? 'passed' : 'failed',
                default => null,
            };
            if (! $decision) {
                $errors[$lineNo] = "{$number}: the decision must be Passed or Did not pass.";

                continue;
            }
            $application = Application::where('number', $number)->first();
            if (! $application) {
                $errors[$lineNo] = "{$number}: no application with this number.";

                continue;
            }
            try {
                $this->recordResult($application, (int) $score, $decision, $staff);
                $recorded++;
            } catch (BusinessRuleException $e) {
                $errors[$lineNo] = "{$number}: {$e->getMessage()}";
            }
        }
        $this->audit->record('result.imported', "Imported {$recorded} exam result".($recorded === 1 ? '' : 's').' from a CSV file'.($errors ? ' ('.count($errors).' lines skipped)' : ''), meta: ['errors' => $errors ?: null]);

        return ['recorded' => $recorded, 'errors' => $errors];
    }

    /** Move an examinee (usually absent) to another session. */
    public function reschedule(Application $application, ExamSession $session, User $staff): void
    {
        $registration = $application->examRegistration;
        if (! $registration || $application->result) {
            throw new BusinessRuleException('Only an examinee without a result can be rescheduled.');
        }

        DB::transaction(function () use ($application, $registration, $session) {
            $this->assertSeat($session);
            $registration->update([
                'exam_session_id' => $session->id, 'attendance' => null, 'attendance_by' => null,
                'attendance_at' => null, 'permit_checked' => false, 'form_checked' => false,
            ]);
            $this->audit->record('exam.rescheduled', "Rescheduled the entrance exam to {$session->label()}", $registration, $application);
            $this->dms->record('exam.scheduled', $application, $this->sessionData($session, $registration->permit_no));
        });
        ExamScheduled::dispatch($application, $session);
    }

    /** Venue checklist and attendance. "Present" needs the permit and form checked. */
    public function updateRoster(Application $application, array $changes, User $staff): void
    {
        $registration = $application->examRegistration;
        if (! $registration || $application->result) {
            throw new BusinessRuleException('Attendance can no longer change once a result is recorded.');
        }

        foreach (['permitChecked' => 'permit_checked', 'formChecked' => 'form_checked'] as $field => $column) {
            if (array_key_exists($field, $changes)) {
                $registration->{$column} = (bool) $changes[$field];
            }
        }
        // FR-17: the long brown envelope handed in at the venue
        if (array_key_exists('envelopeReceived', $changes) && (bool) $changes['envelopeReceived'] !== ($registration->envelope_received_at !== null)) {
            $received = (bool) $changes['envelopeReceived'];
            $registration->fill(['envelope_received_at' => $received ? now() : null, 'envelope_received_by' => $received ? $staff->id : null]);
            $this->audit->record('exam.envelope', $received ? 'Received the long brown envelope at the exam venue' : 'Cleared the envelope receipt', $registration, $application);
        }

        if (array_key_exists('attendance', $changes) && $changes['attendance'] !== $registration->attendance) {
            $value = $changes['attendance'];
            if ($value === 'present' && ! ($registration->permit_checked && $registration->form_checked)) {
                throw new BusinessRuleException('Check the exam permit and the application form before marking present.');
            }
            $registration->fill(['attendance' => $value, 'attendance_by' => $value ? $staff->id : null, 'attendance_at' => $value ? now() : null]);
            $text = match ($value) {
                'present' => 'Marked present at the exam venue (permit and form checked)',
                'absent' => 'Marked absent from the entrance exam',
                default => 'Cleared exam attendance',
            };
            $this->audit->record('exam.attendance', $text, $registration, $application);
            $this->dms->record('exam.attendance', $application, ['attendance' => $value, 'permit' => $registration->permit_no]);
        }
        $registration->save();
    }

    /**
     * FR-07: staff scan the QR code on the printed permit. Checks the token,
     * the exam day and (optionally) the session being run; marks the
     * examinee present. Scanning twice is harmless.
     */
    public function checkIn(string $token, ?ExamSession $expected, User $staff): Application
    {
        $registration = ExamRegistration::where('qr_token', $token)->with(['session', 'application.result'])->first()
            ?? throw new BusinessRuleException('This QR code is not a valid exam permit.');
        $session = $registration->session;
        $application = $registration->application;

        if ($expected && $expected->id !== $session->id) {
            throw new BusinessRuleException("Permit {$registration->permit_no} is for {$session->label()} at {$session->venue}, not this session.");
        }
        $today = now()->setTimezone(config('admission.display_timezone'))->toDateString();
        if (config('admission.exams.check_in_same_day_only') && $session->date->toDateString() !== $today) {
            throw new BusinessRuleException("Permit {$registration->permit_no} is for ".$session->date->format('F j, Y').'.');
        }
        if ($registration->attendance === 'present') {
            return $application;
        }

        $this->updateRoster($application, ['permitChecked' => true, 'formChecked' => true, 'attendance' => 'present'], $staff);

        return $application;
    }

    /** Scheduler: email examinees the day before their session. */
    public function sendReminders(): int
    {
        $tomorrow = now()->setTimezone(config('admission.display_timezone'))->addDay()->toDateString();
        $due = ExamRegistration::whereNull('reminder_sent_at')
            ->whereHas('session', fn ($q) => $q->whereDate('date', $tomorrow))
            ->with(['session', 'application.applicant.user'])->get();

        foreach ($due as $registration) {
            $s = $registration->session;
            $time = ExamSession::BATCHES[$s->batch]['time'];
            $registration->application->applicant->user->notify(new AdmissionNotice('exam_reminder', 'Your entrance exam is tomorrow',
                "Reminder: your entrance exam is tomorrow, {$s->date->format('l, F j')}, {$time}, at {$s->venue}. Bring your printed exam permit ({$registration->permit_no}) and application form, a valid ID and two pencils.",
                $registration->application->number));
            $registration->update(['reminder_sent_at' => now()]);
        }

        return $due->count();
    }

    /** Record (or correct, before release) the internal score and decision. */
    public function recordResult(Application $application, int $score, string $decision, User $staff): ExamResult
    {
        $registration = $application->examRegistration;
        if (! $registration || $registration->attendance !== 'present') {
            throw new BusinessRuleException('Record a result only for an examinee marked present.');
        }
        if ($application->result?->is_released) {
            throw new BusinessRuleException("A released result can't be changed.");
        }

        return DB::transaction(function () use ($application, $score, $decision, $staff) {
            $changed = $application->result !== null;
            $result = ExamResult::updateOrCreate(['application_id' => $application->id], [
                'score' => $score, 'decision' => $decision, 'recorded_by' => $staff->id, 'recorded_at' => now(), 'is_released' => false,
            ]);
            $this->status->transition($application, ApplicationStatus::from($decision), $staff);
            $label = $decision === 'passed' ? 'Passed' : 'Did not pass';
            $this->audit->record('result.recorded', ($changed ? 'Updated' : 'Recorded')." exam result: {$label}", $result, $application);

            return $result;
        });
    }

    /**
     * Controlled release (FR-08): only released results are visible to
     * applicants. Returns the applications whose result was released.
     *
     * @param  Collection<int, Application>  $applications
     */
    public function release(Collection $applications, User $staff): Collection
    {
        $released = DB::transaction(function () use ($applications, $staff) {
            $released = collect();
            foreach ($applications as $application) {
                $result = $application->result;
                if (! $result || $result->is_released) {
                    continue;
                }
                $result->update(['is_released' => true, 'released_by' => $staff->id, 'released_at' => now()]);
                $this->audit->record('result.released', 'Released the exam result to the applicant', $result, $application);
                $this->dms->record('exam.result', $application, [
                    'decision' => $result->decision,
                    'score' => ['value' => $result->score, 'classification' => 'restricted'],
                    'released_at' => $result->released_at->toIso8601String(),
                ]);
                $this->offers->afterResultReleased($application, $staff);
                $released->push($application);
            }
            if ($n = $released->count()) {
                $this->audit->record('result.released_batch', "Released {$n} exam result".($n === 1 ? '' : 's').' through the system', meta: ['applications' => $released->pluck('number')->all()]);
            }

            return $released;
        });
        $released->each(fn (Application $a) => ResultReleased::dispatch($a));

        return $released;
    }

    private function assertSeat(ExamSession $session): void
    {
        $session = ExamSession::lockForUpdate()->findOrFail($session->id);
        if ($session->registrations()->count() >= $session->capacity) {
            throw new BusinessRuleException('That session is full. Pick another one.');
        }
    }

    /** Sessions an applicant may choose when self-scheduling is on (D17): upcoming, with seats. */
    public function openSessions(): Collection
    {
        return ExamSession::where('admission_period_id', AdmissionPeriod::current()->id)
            ->whereDate('date', '>', now()->toDateString())
            ->withCount('registrations')->orderBy('date')->orderBy('batch')->get()
            ->filter(fn (ExamSession $s) => $s->registrations_count < $s->capacity)->values();
    }

    /** Signed value printed as the permit's QR code; can't be guessed from the permit number. */
    public function qrToken(string $permit, Application $application): string
    {
        return substr(hash_hmac('sha256', $permit.'|'.$application->id, (string) config('app.key')), 0, 40);
    }

    private function sessionData(ExamSession $session, string $permit): array
    {
        return ['date' => $session->date->toDateString(), 'batch' => $session->batch, 'venue' => $session->venue, 'permit' => $permit];
    }

    /** EX-27-00001: "27" from the academic year (2027–2028) + the application's number. */
    /** 1.1: the Applicant ID (2026-00001), numbered in approval order; the unique index guards a race. */
    private function nextApplicantNo(): string
    {
        $year = now()->year;
        $last = Application::withTrashed()->where('applicant_no', 'like', "{$year}-%")->lockForUpdate()->max('applicant_no');

        return sprintf('%d-%05d', $year, $last ? (int) substr($last, 5) + 1 : 1);
    }

    private function permitNumber(Application $application): string
    {
        $year = substr($application->period->academic_year, 2, 2);

        return sprintf('%s-%s-%s', config('admission.permit_prefix'), $year, substr($application->number, -5));
    }
}
