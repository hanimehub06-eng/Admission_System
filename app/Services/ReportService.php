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
    /** report key => [title, roles allowed] */
    public const REPORTS = [
        'applicants-per-program' => ['Applicants per program', ['admission']],
        'status-breakdown' => ['Applications by status', ['admission']],
        'document-completion' => ['Document completion', ['admission']],
        'exam-attendance' => ['Exam attendance', ['admission']],
        'pass-rate' => ['Exam pass rate per program', ['admission']],
        'daily-submissions' => ['Daily submissions', ['admission']],
        'medical-completion' => ['Medical completion', ['clinic', 'admission']],
        'interviews' => ['Interviews', ['college_dean', 'admission']],
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
        $first = $this->apps($periodId)->join('application_program_choices as c', 'c.application_id', '=', 'a.id')
            ->where('c.rank', 1)->where('a.status', '!=', 'draft')
            ->groupBy('c.program_id')->pluck(DB::raw('count(*)'), 'c.program_id');
        $filled = Program::filledCounts();

        return [
            'columns' => ['Code', 'Program', 'Applicants (1st choice)', 'Slots', 'Slots accepted', 'Slots left'],
            'rows' => Program::orderBy('code')->get()->map(fn (Program $p) => [
                $p->code, $p->name, (int) ($first[$p->id] ?? 0), $p->slots, $filled[$p->id] ?? 0, max(0, $p->slots - ($filled[$p->id] ?? 0)),
            ])->all(),
        ];
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
        if (! $user->hasRole(Role::Admission->value)) {
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
