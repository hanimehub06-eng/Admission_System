<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Models\AdmissionPeriod;
use App\Models\Program;
use App\Models\Requirement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Reports (FR-13) for the current admission period, computed in the
 * database and exportable to Excel and PDF. Role-scoped (§10.2):
 * admission personnel see the admission reports; the clinic sees medical
 * completion; a College Dean sees interviews for the dean's own college.
 *
 * A report is ['title', 'columns' => [...], 'rows' => [[...], ...]].
 */
class ReportService
{
    private const OFFICE = ['admission', 'admission_head', 'read_only'];

    /** report key => [title, roles allowed] (FR-27; FR-29 admitted applicants) */
    public const REPORTS = [
        'applicants-per-program' => ['Applicants per program', self::OFFICE],
        'status-breakdown' => ['Applications by status', self::OFFICE],
        'document-completion' => ['Document completion', self::OFFICE],
        'exam-attendance' => ['Exam attendance', [...self::OFFICE, 'proctor']],
        'pass-rate' => ['Exam pass rate per program', self::OFFICE],
        'daily-submissions' => ['Daily submissions', self::OFFICE],
        'possible-duplicates' => ['Possible duplicate applicants', ['admission', 'admission_head']],
        'admitted-applicants' => ['Admitted applicants', self::OFFICE],
        'medical-completion' => ['Medical completion', ['clinic', 'admission', 'admission_head']],
        'interviews' => ['Interviews', ['college_dean', 'admission', 'admission_head', 'read_only']],
    ];

    /** The reports this user may open. */
    public function available(User $user): array
    {
        return collect(self::REPORTS)
            ->filter(fn ($r) => $user->hasAnyRole($r[1]))
            ->map(fn ($r, $key) => ['key' => $key, 'title' => $r[0]])
            ->values()->all();
    }

    public function build(string $key, User $user): array
    {
        [$title, $roles] = self::REPORTS[$key] ?? abort(404);
        if (! $user->hasAnyRole($roles)) {
            throw new AuthorizationException('This report is not available to your role.');
        }
        $period = AdmissionPeriod::current();
        $method = Str::camel($key);

        return ['title' => $title, 'period' => $period->label] + $this->{$method}($period->id, $user);
    }

    /** CSV (FR-27), with a BOM so Excel opens accented names correctly. */
    public function toCsv(array $report): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $report['columns']);
        foreach ($report['rows'] as $row) {
            // A leading = + - @ would run as a formula in Excel (CSV injection)
            fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $row));
        }
        rewind($out);

        return stream_get_contents($out);
    }

    /** Excel file (FR-13). Returns the path of a temporary .xlsx. */
    public function toXlsx(array $report): string
    {
        $path = tempnam(sys_get_temp_dir(), 'report').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValuesWithStyle([$report['title'].' · '.$report['period']], (new Style)->withFontBold(true)));
        $writer->addRow(Row::fromValuesWithStyle($report['columns'], (new Style)->withFontBold(true)));
        foreach ($report['rows'] as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return $path;
    }

    private function applicantsPerProgram(int $periodId): array
    {
        // FR-27: by choice (1st, 2nd, 3rd), submitted applications only
        $counts = $this->apps($periodId)->join('application_program_choices as c', 'c.application_id', '=', 'a.id')
            ->where('a.status', '!=', 'draft')
            ->groupBy('c.program_id', 'c.rank')->get(['c.program_id', 'c.rank', DB::raw('count(*) as n')])
            ->groupBy('program_id')->map(fn ($rows) => $rows->pluck('n', 'rank'));
        $filled = Program::filledCounts();

        return [
            'columns' => ['Code', 'Program', '1st choice', '2nd choice', '3rd choice', 'Total', 'Slots', 'Slots accepted', 'Slots left'],
            'rows' => Program::orderBy('code')->get()->map(function (Program $p) use ($counts, $filled) {
                $by = $counts[$p->id] ?? collect();
                $n = fn ($rank) => (int) ($by[$rank] ?? 0);

                return [$p->code, $p->name, $n(1), $n(2), $n(3), $n(1) + $n(2) + $n(3), $p->slots, $filled[$p->id] ?? 0, max(0, $p->slots - ($filled[$p->id] ?? 0))];
            })->all(),
        ];
    }

    /** FR-25: same LRN can't happen (unique); same name and birthday is flagged at save. */
    private function possibleDuplicates(int $periodId): array
    {
        $rows = DB::table('applicants as p')->whereNotNull('p.possible_duplicate_of')->whereNull('p.deleted_at')
            ->join('applicants as o', 'o.id', '=', 'p.possible_duplicate_of')
            ->leftJoin('applications as a', fn ($j) => $j->on('a.applicant_id', '=', 'p.id')->where('a.admission_period_id', $periodId))
            ->leftJoin('applications as oa', fn ($j) => $j->on('oa.applicant_id', '=', 'o.id')->where('oa.admission_period_id', $periodId))
            ->orderBy('p.last_name')->orderBy('p.first_name')
            ->get(['a.number', 'p.last_name', 'p.first_name', 'p.birthday', 'p.lrn', 'oa.number as other_number', 'o.lrn as other_lrn']);

        return [
            'columns' => ['Application no.', 'Name', 'Birthday', 'LRN', 'Possible duplicate of', 'Their LRN'],
            'rows' => $rows->map(fn ($r) => [$r->number, $r->last_name.', '.$r->first_name, $r->birthday ? substr($r->birthday, 0, 10) : '', $r->lrn, $r->other_number, $r->other_lrn])->all(),
        ];
    }

    /** FR-29: the same fields the Enrollment System receives. */
    private function admittedApplicants(int $periodId): array
    {
        return app(AdmittedApplicantsService::class)->report();
    }

    private function statusBreakdown(int $periodId): array
    {
        $counts = $this->apps($periodId)->groupBy('a.status')->pluck(DB::raw('count(*)'), 'a.status');

        return [
            'columns' => ['Status', 'Applications'],
            'rows' => collect(ApplicationStatus::cases())->map(fn ($s) => [$s->label(), (int) ($counts[$s->value] ?? 0)])->all(),
        ];
    }

    private function documentCompletion(int $periodId): array
    {
        $counts = $this->apps($periodId)->join('application_documents as d', 'd.application_id', '=', 'a.id')
            ->where('d.is_current', true)
            ->groupBy('d.requirement_id', 'd.status')
            ->get(['d.requirement_id', 'd.status', DB::raw('count(*) as n'), DB::raw('sum(case when d.photocopy_received_at is null then 0 else 1 end) as copies')])
            ->groupBy('requirement_id');

        return [
            'columns' => ['Requirement', 'Uploaded', 'For review', 'Verified', 'Requires resubmission', 'Photocopy received'],
            'rows' => Requirement::admission()->where('is_uploadable', true)->get()->map(function (Requirement $r) use ($counts) {
                $by = ($counts[$r->id] ?? collect())->keyBy('status');
                $n = fn ($s) => (int) ($by[$s]->n ?? 0);

                return [$r->title, $n('pending') + $n('verified') + $n('resubmit'), $n('pending'), $n('verified'), $n('resubmit'), (int) ($counts[$r->id] ?? collect())->sum('copies')];
            })->all(),
        ];
    }

    private function examAttendance(int $periodId): array
    {
        $rows = DB::table('exam_sessions as s')->where('s.admission_period_id', $periodId)
            ->leftJoin('exam_registrations as r', 'r.exam_session_id', '=', 's.id')
            ->groupBy('s.id', 's.date', 's.batch', 's.venue', 's.capacity')
            ->orderBy('s.date')->orderBy('s.batch')
            ->get(['s.date', 's.batch', 's.venue', 's.capacity', DB::raw('count(r.id) as registered'),
                DB::raw("sum(case when r.attendance = 'present' then 1 else 0 end) as present"),
                DB::raw("sum(case when r.attendance = 'absent' then 1 else 0 end) as absent")]);

        return [
            'columns' => ['Date', 'Batch', 'Venue', 'Capacity', 'Registered', 'Present', 'Absent', 'Attendance %'],
            'rows' => $rows->map(fn ($s) => [
                substr($s->date, 0, 10), $s->batch, $s->venue, (int) $s->capacity, (int) $s->registered, (int) $s->present, (int) $s->absent,
                $s->registered ? round($s->present / $s->registered * 100, 1) : 0,
            ])->all(),
        ];
    }

    private function passRate(int $periodId): array
    {
        $rows = $this->apps($periodId)
            ->join('exam_results as res', 'res.application_id', '=', 'a.id')
            ->join('application_program_choices as c', fn ($j) => $j->on('c.application_id', '=', 'a.id')->where('c.rank', 1))
            ->join('programs as p', 'p.id', '=', 'c.program_id')
            ->groupBy('p.code', 'p.name')->orderBy('p.code')
            ->get(['p.code', 'p.name', DB::raw('count(*) as examined'),
                DB::raw("sum(case when res.decision = 'passed' then 1 else 0 end) as passed"),
                DB::raw('sum(case when res.is_released then 1 else 0 end) as released')]);

        return [
            'columns' => ['Code', 'Program', 'Results recorded', 'Passed', 'Did not pass', 'Pass rate %', 'Released'],
            'rows' => $rows->map(fn ($r) => [
                $r->code, $r->name, (int) $r->examined, (int) $r->passed, $r->examined - $r->passed,
                $r->examined ? round($r->passed / $r->examined * 100, 1) : 0, (int) $r->released,
            ])->all(),
        ];
    }

    private function dailySubmissions(int $periodId): array
    {
        $tz = config('admission.display_timezone');
        $days = $this->apps($periodId)->whereNotNull('a.submitted_at')->pluck('a.submitted_at')
            ->map(fn ($at) => now()->parse($at, 'UTC')->setTimezone($tz)->toDateString())
            ->countBy()->sortKeys();

        return [
            'columns' => ['Date', 'Applications submitted'],
            'rows' => $days->map(fn ($n, $day) => [$day, $n])->values()->all(),
        ];
    }

    private function medicalCompletion(int $periodId): array
    {
        $counts = $this->apps($periodId)->join('application_documents as d', 'd.application_id', '=', 'a.id')
            ->where('d.is_current', true)->whereIn('a.status', ['for_medical', 'admitted'])
            ->groupBy('d.requirement_id', 'd.status')
            ->get(['d.requirement_id', 'd.status', DB::raw('count(*) as n'), DB::raw('sum(case when d.falsification_flag then 1 else 0 end) as flagged')])
            ->groupBy('requirement_id');
        $waiting = $this->apps($periodId)->where('a.status', 'for_medical')->count();
        $admitted = $this->apps($periodId)->where('a.status', 'admitted')->count();

        $rows = Requirement::medical()->get()->map(function (Requirement $r) use ($counts) {
            $by = ($counts[$r->id] ?? collect())->keyBy('status');
            $n = fn ($s) => (int) ($by[$s]->n ?? 0);

            return [$r->title, $n('pending') + $n('verified') + $n('resubmit'), $n('pending'), $n('verified'), $n('resubmit'), (int) ($counts[$r->id] ?? collect())->sum('flagged')];
        })->all();
        $rows[] = ['Applicants waiting for medical clearance', $waiting, '', '', '', ''];
        $rows[] = ['Admitted (medically cleared)', $admitted, '', '', '', ''];

        return [
            'columns' => ['Requirement', 'Uploaded', 'For checking', 'Verified', 'Requires resubmission', 'Flagged'],
            'rows' => $rows,
        ];
    }

    /** Dean: own college only. Grades are not in the report (Restricted). */
    private function interviews(int $periodId, User $user): array
    {
        $query = DB::table('interview_schedules as s')->where('s.admission_period_id', $periodId)
            ->join('programs as p', 'p.id', '=', 's.program_id')
            ->leftJoin('interview_assignments as i', 'i.interview_schedule_id', '=', 's.id');
        if (! $user->isAdmissionOffice() && ! $user->hasRole(Role::ReadOnly->value)) {
            $query->where('p.college_id', $user->deanOf?->id ?? 0);
        }
        $rows = $query->groupBy('s.id', 'p.code', 's.date', 's.time', 's.venue', 's.capacity')->orderBy('s.date')
            ->get(['p.code', 's.date', 's.time', 's.venue', 's.capacity', DB::raw('count(i.id) as assigned'),
                DB::raw("sum(case when i.outcome = 'passed' then 1 else 0 end) as passed"),
                DB::raw("sum(case when i.outcome = 'failed' then 1 else 0 end) as failed")]);

        return [
            'columns' => ['Program', 'Date', 'Time', 'Venue', 'Capacity', 'Assigned', 'Passed', 'Failed', 'Not yet recorded'],
            'rows' => $rows->map(fn ($r) => [
                $r->code, substr($r->date, 0, 10), $r->time, $r->venue, (int) $r->capacity, (int) $r->assigned,
                (int) $r->passed, (int) $r->failed, $r->assigned - $r->passed - $r->failed,
            ])->all(),
        ];
    }

    private function apps(int $periodId)
    {
        return DB::table('applications as a')->where('a.admission_period_id', $periodId)->whereNull('a.deleted_at');
    }
}
