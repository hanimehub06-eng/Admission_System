<?php

namespace App\Http\Presenters;

use App\Models\AdmissionPeriod;
use App\Models\Application;
use App\Models\ExamSession;
use App\Models\Program;
use App\Models\Requirement;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Builds the admission office panel's state (ADM.state in admin-store.js):
 * { programs, sessions, applicants, settings, audit, staff }. Every screen,
 * chart, report and CSV export in the panel is derived from it.
 *
 * Built from plain query rows, not Eloquent models, in batches of
 * BATCH applicants: `applicants` is a generator, so the controller streams
 * the JSON and memory stays flat at peak (~20,000 applicants). Measured
 * numbers are in PROGRESS.md.
 *
 * The internal score and interview grade are included for admission
 * personnel only (Restricted, §8.3); the clinic never gets them.
 */
class OfficeStatePresenter
{
    private const BATCH = 1000;

    private DateTimeZone $utc;

    private DateTimeZone $local;

    public function __construct()
    {
        $this->utc = new DateTimeZone('UTC');
        $this->local = new DateTimeZone(config('admission.display_timezone'));
    }

    public function state(User $staff): array
    {
        $period = AdmissionPeriod::current();

        return [
            'version' => 2,
            'staff' => [
                'name' => $staff->name,
                'initials' => $staff->initials(),
                'role' => $staff->primaryRole()?->label(),
                'roleKey' => $staff->primaryRole()?->value,
                'email' => $staff->email,
            ],
            'programs' => Program::orderBy('id')->get()->map(fn (Program $p) => [
                'code' => $p->code, 'name' => $p->name, 'slots' => $p->slots,
            ])->all(),
            'sessions' => ExamSession::where('admission_period_id', $period->id)->orderBy('date')->orderBy('batch')->get()
                ->map(fn (ExamSession $s) => $this->session($s))->all(),
            'applicants' => $this->allApplicants($staff, $period->id),
            'settings' => [
                'deadline' => $period->application_deadline->toDateString(),
                'period' => $period->label,
                'inactiveDocs' => Requirement::admission()->where('is_active', false)->pluck('key')->all(),
            ],
            // Applicant sign-ins are audited too, but they are not office activity
            'audit' => DB::table('audit_logs')->whereNull('application_id')->where('action', '!=', 'applicant.login')->orderBy('id')
                ->get(['actor_name', 'description', 'created_at'])
                ->map(fn ($l) => $this->entry($l))->all(),
        ];
    }

    /** One applicant, after an action (the panel replaces its copy). */
    public function applicant(Application $application, ?User $staff = null): array
    {
        return $this->applicants($staff ?? request()->user(), [$application->id])[0];
    }

    /** Every applicant of the period, one batch at a time. */
    private function allApplicants(User $staff, int $periodId): \Generator
    {
        $ids = DB::table('applications')->where('admission_period_id', $periodId)->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();
        foreach (array_chunk($ids, self::BATCH) as $batch) {
            yield from $this->applicants($staff, $batch);
        }
    }

    public function session(ExamSession $s): array
    {
        return [
            'id' => $s->publicId(),
            'date' => $s->date->toDateString(),
            'batch' => $s->batch,
            'venue' => $s->venue,
            'capacity' => $s->capacity,
        ];
    }

    /** The given applications, in id order. */
    private function applicants(User $staff, array $ids): array
    {
        $withScore = $staff->hasRole('admission');

        $rows = DB::table('applications as a')
            ->join('applicants as p', 'p.id', '=', 'a.applicant_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('exam_registrations as r', 'r.application_id', '=', 'a.id')
            ->leftJoin('exam_results as res', 'res.application_id', '=', 'a.id')
            ->leftJoin('users as rec', 'rec.id', '=', 'res.recorded_by')
            ->leftJoin('programs as op', 'op.id', '=', 'a.offered_program_id')
            ->whereIn('a.id', $ids)
            ->whereNull('a.deleted_at')
            ->orderBy('a.id')
            ->get([
                'a.id', 'a.number', 'a.status', 'a.created_at', 'a.submitted_at', 'a.medical_submitted_at',
                'a.acceptance_deadline', 'a.offer_accepted_at', 'a.admitted_at', 'a.student_number',
                'p.possible_duplicate_of', 'op.code as offered_program',
                'p.lrn', 'p.last_name', 'p.first_name', 'p.middle_name', 'p.sex', 'p.phone', 'p.is_resident', 'p.district',
                'p.barangay', 'p.address', 'p.city', 'p.province', 'p.birthday', 'p.school_type', 'p.last_school', 'p.strand',
                'p.applicant_type', 'p.has_guardian', 'u.email',
                'r.exam_session_id', 'r.permit_no', 'r.attendance', 'r.permit_checked', 'r.form_checked',
                'res.score', 'res.decision', 'res.recorded_at', 'res.is_released', 'res.released_at', 'rec.name as recorder',
            ]);
        if ($rows->isEmpty()) {
            return [];
        }

        $scope = fn ($q, string $col) => $q->whereIn($col, $ids);

        $choices = $scope(DB::table('application_program_choices as c')->join('programs as pr', 'pr.id', '=', 'c.program_id'), 'c.application_id')
            ->orderBy('c.rank')->get(['c.application_id', 'pr.code'])
            ->groupBy('application_id');

        $documents = $scope(DB::table('application_documents as d')
            ->join('requirements as q', 'q.id', '=', 'd.requirement_id')
            ->leftJoin('users as rv', 'rv.id', '=', 'd.reviewed_by')
            ->where('d.is_current', true), 'd.application_id')
            ->orderBy('d.id')
            ->get(['d.application_id', 'q.key', 'q.stage', 'd.status', 'd.original_name', 'd.size', 'd.created_at', 'rv.name as reviewer', 'd.reviewed_at', 'd.remark',
                'd.scan_status', 'd.photocopy_received_at', 'd.falsification_flag', 'd.falsification_note'])
            ->groupBy('application_id');

        $interviews = $scope(DB::table('interview_assignments as i')->join('interview_schedules as s', 's.id', '=', 'i.interview_schedule_id'), 'i.application_id')
            ->get(['i.application_id', 's.id as schedule_id', 's.date', 's.time', 's.venue', 'i.outcome', 'i.grade'])
            ->keyBy('application_id');

        // Possible duplicates (FR-01): the other applicant's application number
        $dupIds = $rows->pluck('possible_duplicate_of')->filter()->unique()->all();
        $dupNumbers = $dupIds ? DB::table('applications')->whereIn('applicant_id', $dupIds)->orderBy('id')->pluck('number', 'applicant_id') : collect();

        $history = $scope(DB::table('audit_logs'), 'application_id')
            ->orderBy('id')->get(['application_id', 'actor_name', 'description', 'created_at'])
            ->groupBy('application_id');

        $out = [];
        foreach ($rows as $a) {
            $docs = ['admission' => [], 'medical' => []];
            foreach ($documents[$a->id] ?? [] as $d) {
                $docs[$d->stage][$d->key] = array_filter([
                    'status' => $d->status,
                    'file' => $d->original_name,
                    'size' => (int) $d->size,
                    'uploadedAt' => $this->at($d->created_at),
                    'reviewedBy' => $d->reviewer,
                    'reviewedAt' => $this->at($d->reviewed_at),
                    'remark' => $d->remark,
                    'scan' => $d->scan_status,
                    'photocopyAt' => $this->at($d->photocopy_received_at),
                    'flagged' => $d->falsification_flag ? true : null,
                    'flagNote' => $d->falsification_flag ? $d->falsification_note : null,
                ], fn ($v) => $v !== null);
            }
            $resident = (bool) $a->is_resident;

            $item = [
                'id' => $a->number,
                'lrn' => $a->lrn,
                'lastName' => $a->last_name,
                'firstName' => $a->first_name,
                'middleName' => $a->middle_name,
                'sex' => $a->sex,
                'email' => $a->email,
                'phone' => $a->phone,
                'resident' => $resident,
                // The applicant form has no district/barangay fields yet (decision T7): "—" = unknown
                'district' => $a->district !== null ? (int) $a->district : ($resident ? '—' : null),
                'barangay' => $a->barangay ?? ($resident ? '—' : ''),
                'address' => implode(', ', array_filter([$a->address, $a->city, $a->province])),
                'birthday' => $a->birthday ? substr($a->birthday, 0, 10) : null,
                'schoolType' => $a->school_type,
                'lastSchool' => $a->last_school,
                'strand' => $a->strand,
                'applicantType' => $a->applicant_type ?? 'grade12',
                'hasGuardian' => (bool) $a->has_guardian,
                'programs' => ($choices[$a->id] ?? collect())->pluck('code')->all(),
                'createdAt' => $this->at($a->created_at),
                'submittedAt' => $this->at($a->submitted_at),
                'docs' => $docs['admission'],
                'exam' => $a->permit_no ? [
                    'sessionId' => 'S'.$a->exam_session_id,
                    'permit' => $a->permit_no,
                    'attendance' => $a->attendance,
                    'permitChecked' => (bool) $a->permit_checked,
                    'formChecked' => (bool) $a->form_checked,
                ] : null,
                'result' => $a->decision ? array_filter([
                    'score' => $withScore ? (int) $a->score : null,
                    'decision' => $a->decision,
                    'recordedBy' => $a->recorder,
                    'recordedAt' => $this->at($a->recorded_at),
                    'released' => (bool) $a->is_released,
                    'releasedAt' => $this->at($a->released_at),
                ], fn ($v) => $v !== null) : null,
                'history' => ($history[$a->id] ?? collect())->map(fn ($l) => $this->entry($l))->all(),
                // Not used by the panel yet: the updated paper's stages (offer, interview, admitted)
                'status' => $a->status,
                'possibleDuplicateOf' => $a->possible_duplicate_of ? ($dupNumbers[$a->possible_duplicate_of] ?? null) : null,
                'offer' => $a->offered_program ? [
                    'program' => $a->offered_program,
                    'deadline' => $this->at($a->acceptance_deadline),
                    'acceptedAt' => $this->at($a->offer_accepted_at),
                ] : null,
                'interview' => isset($interviews[$a->id]) ? array_filter([
                    'scheduleId' => (int) $interviews[$a->id]->schedule_id,
                    'date' => substr($interviews[$a->id]->date, 0, 10),
                    'time' => $interviews[$a->id]->time,
                    'venue' => $interviews[$a->id]->venue,
                    'outcome' => $interviews[$a->id]->outcome,
                    'grade' => $withScore && $interviews[$a->id]->grade !== null ? (int) $interviews[$a->id]->grade : null,
                ], fn ($v) => $v !== null) : null,
                'admittedAt' => $this->at($a->admitted_at),
                'studentNumber' => $a->student_number,
            ];
            // Medical requirements open once the slot is accepted (Application::medicalApplies)
            if (in_array($a->status, ['for_medical', 'admitted'], true)) {
                $item['medical'] = ['files' => $docs['medical'], 'submittedAt' => $this->at($a->medical_submitted_at)];
            }
            $out[] = $item;
        }

        return $out;
    }

    private function entry(object $log): array
    {
        return ['at' => $this->at($log->created_at), 'by' => $log->actor_name, 'text' => $log->description];
    }

    /** "2026-10-17T09:05" in Philippine time, the format the panel uses. Stored values are UTC. */
    private function at(?string $utc): ?string
    {
        return $utc === null ? null : (new DateTimeImmutable($utc, $this->utc))->setTimezone($this->local)->format('Y-m-d\TH:i');
    }
}
