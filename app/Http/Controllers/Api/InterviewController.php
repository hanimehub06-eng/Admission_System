<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\InterviewAssignment;
use App\Models\InterviewSchedule;
use App\Models\Program;
use App\Services\InterviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Interviews (FR-17), API only: neither panel has interview screens yet.
 * Admission personnel see every program; a College Dean sees and records
 * only the dean's own college. Grades are Restricted: staff only.
 */
class InterviewController extends Controller
{
    public function __construct(private InterviewService $interviews) {}

    /** Schedules, assignments and applicants waiting for an interview, within the caller's scope. */
    public function index(Request $request): JsonResponse
    {
        $programs = $this->interviews->visiblePrograms($request->user())->where('requires_interview', true);
        $ids = $programs->pluck('id');

        $schedules = InterviewSchedule::whereIn('program_id', $ids)->with(['program', 'assignments.application.applicant'])
            ->orderBy('date')->get();
        $waiting = Application::where('status', ApplicationStatus::ForInterview)->with(['applicant', 'choices.program', 'interview'])->get()
            ->filter(fn (Application $a) => $ids->contains($a->choices->first()?->program_id) && ! $a->interview);

        return response()->json(['data' => [
            'programs' => $programs->map(fn (Program $p) => ['code' => $p->code, 'name' => $p->name])->values(),
            'schedules' => $schedules->map(fn (InterviewSchedule $s) => [
                'id' => $s->id,
                'program' => $s->program->code,
                'date' => $s->date->toDateString(),
                'time' => $s->time,
                'venue' => $s->venue,
                'capacity' => $s->capacity,
                'applicants' => $s->assignments->map(fn (InterviewAssignment $i) => $this->assignment($i))->values(),
            ])->values(),
            'waiting' => $waiting->map(fn (Application $a) => [
                'id' => $a->number, 'name' => $a->applicant->fullName(true), 'program' => $a->choices->first()?->program->code,
            ])->values(),
        ]]);
    }

    /** Admission personnel: new interview schedule for a program. */
    public function storeSchedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'program' => ['required', 'string', 'exists:programs,code'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['nullable', 'string', 'max:40'],
            'venue' => ['required', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'between:1,500'],
        ]);
        $program = Program::where('code', $data['program'])->firstOrFail();
        $schedule = $this->interviews->createSchedule($program, $data['date'], $data['time'] ?? null, $data['venue'], $data['capacity'], $request->user());

        return response()->json(['data' => ['id' => $schedule->id, 'program' => $program->code, 'date' => $schedule->date->toDateString(), 'time' => $schedule->time, 'venue' => $schedule->venue, 'capacity' => $schedule->capacity]], 201);
    }

    /** Admission personnel: put an applicant on a schedule. Body: { scheduleId } */
    public function assign(Request $request, Application $application): JsonResponse
    {
        $data = $request->validate(['scheduleId' => ['required', 'integer', 'exists:interview_schedules,id']]);
        $assignment = $this->interviews->assign($application, InterviewSchedule::findOrFail($data['scheduleId']), $request->user());

        return response()->json(['data' => $this->assignment($assignment->load('application.applicant', 'schedule'))]);
    }

    /** College Dean: grade (0–100, Restricted) and outcome. Body: { grade, outcome } */
    public function record(Request $request, Application $application): JsonResponse
    {
        $data = $request->validate([
            'grade' => ['required', 'integer', 'between:0,100'],
            'outcome' => ['required', Rule::in([InterviewAssignment::PASSED, InterviewAssignment::FAILED])],
        ]);
        $assignment = $this->interviews->record($application, $data['grade'], $data['outcome'], $request->user());

        return response()->json(['data' => $this->assignment($assignment->load('application.applicant', 'schedule')) + ['status' => $application->fresh()->status->value]]);
    }

    private function assignment(InterviewAssignment $i): array
    {
        return [
            'id' => $i->application->number,
            'name' => $i->application->applicant->fullName(true),
            'scheduleId' => $i->interview_schedule_id,
            'outcome' => $i->outcome,
            'grade' => $i->grade,   // staff only: this controller is never routed to applicants
            'recordedAt' => $i->recorded_at?->toIso8601String(),
        ];
    }
}
