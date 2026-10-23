<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\AdmissionPeriod;
use App\Models\Application;
use App\Models\InterviewAssignment;
use App\Models\InterviewSchedule;
use App\Models\Program;
use App\Models\User;
use App\Notifications\AdmissionNotice;
use App\Services\Dms\DmsSync;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Interviews (FR-17). Admission personnel create schedules per program and
 * assign applicants who passed the exam and whose program requires an
 * interview. The College Dean of that program's college records the grade
 * (Restricted) and Passed/Failed. Passed → offer; Failed → final.
 * Applicants see their schedule and outcome, never the grade.
 */
class InterviewService
{
    public function __construct(
        private ApplicationStatusService $status,
        private OfferService $offers,
        private AuditLogger $audit,
        private DmsSync $dms,
    ) {}

    public function createSchedule(Program $program, string $date, ?string $time, string $venue, int $capacity, User $staff): InterviewSchedule
    {
        if (! $program->requires_interview) {
            throw new BusinessRuleException("{$program->code} doesn't require an interview.");
        }
        $schedule = InterviewSchedule::create([
            'admission_period_id' => AdmissionPeriod::current()->id,
            'program_id' => $program->id,
            'date' => $date, 'time' => $time, 'venue' => trim($venue), 'capacity' => $capacity, 'created_by' => $staff->id,
        ]);
        $this->audit->record('interview.schedule_created', "Created interview schedule {$schedule->label()} at {$schedule->venue} ({$capacity} seats)", $schedule);

        return $schedule;
    }

    /** Assign (or move) an applicant to an interview schedule. Capacity is enforced under a lock. */
    public function assign(Application $application, InterviewSchedule $schedule, User $staff): InterviewAssignment
    {
        if ($application->status !== ApplicationStatus::ForInterview) {
            throw new BusinessRuleException('Only applicants waiting for an interview can be scheduled.');
        }
        if ($application->interview?->outcome) {
            throw new BusinessRuleException('The interview outcome is already recorded.');
        }
        if ($application->firstChoice()?->id !== $schedule->program_id) {
            throw new BusinessRuleException("This schedule is for {$schedule->program->code}, not the applicant's first choice.");
        }

        $assignment = DB::transaction(function () use ($application, $schedule, $staff) {
            $locked = InterviewSchedule::lockForUpdate()->findOrFail($schedule->id);
            $booked = $locked->assignments()->where('application_id', '!=', $application->id)->count();
            if ($booked >= $locked->capacity) {
                throw new BusinessRuleException('That interview schedule is full. Pick another one.');
            }
            $assignment = InterviewAssignment::updateOrCreate(['application_id' => $application->id], [
                'interview_schedule_id' => $schedule->id, 'assigned_by' => $staff->id,
            ]);
            $this->audit->record('interview.assigned', "Scheduled the interview · {$schedule->label()}", $assignment, $application);
            $this->dms->record('interview.scheduled', $application, ['program' => $schedule->program->code, 'date' => $schedule->date->toDateString(), 'time' => $schedule->time, 'venue' => $schedule->venue]);

            return $assignment;
        });

        $application->applicant->user->notify(new AdmissionNotice('interview_scheduled', 'Your interview schedule',
            "Your {$schedule->program->name} interview is on {$schedule->date->format('l, F j, Y')}".($schedule->time ? ", {$schedule->time}" : '')." at {$schedule->venue}.",
            $application->number));

        return $assignment;
    }

    /** The dean records the grade and outcome (own college only). Final once recorded. */
    public function record(Application $application, int $grade, string $outcome, User $dean): InterviewAssignment
    {
        $assignment = $application->interview?->load('schedule.program')
            ?? throw new BusinessRuleException('This applicant has no interview scheduled.');
        $this->assertDeanOf($dean, $assignment->schedule->program);
        if ($assignment->outcome) {
            throw new BusinessRuleException('The interview outcome is already recorded.');
        }

        DB::transaction(function () use ($application, $assignment, $grade, $outcome, $dean) {
            $assignment->update(['grade' => $grade, 'outcome' => $outcome, 'recorded_by' => $dean->id, 'recorded_at' => now()]);
            $label = $outcome === InterviewAssignment::PASSED ? 'Passed' : 'Failed';
            $this->audit->record('interview.recorded', "Recorded the interview outcome: {$label}", $assignment, $application);
            $this->dms->record('interview.recorded', $application, [
                'program' => $assignment->schedule->program->code,
                'outcome' => $outcome,
                'grade' => ['value' => $grade, 'classification' => 'restricted'],
            ]);

            $outcome === InterviewAssignment::PASSED
                ? $this->offers->offer($application, $dean)
                : $this->status->transition($application, ApplicationStatus::InterviewFailed, $dean);
        });

        return $assignment->fresh();
    }

    /** Programs whose interviews the user may see: the dean's college, or all for admission personnel. */
    public function visiblePrograms(User $user)
    {
        if ($user->hasRole(Role::Admission->value)) {
            return Program::orderBy('code')->get();
        }
        $college = $user->deanOf;

        return $college ? $college->programs()->orderBy('code')->get() : collect();
    }

    private function assertDeanOf(User $dean, Program $program): void
    {
        if (! $dean->hasRole(Role::CollegeDean->value) || ! $program->college_id || $dean->deanOf?->id !== $program->college_id) {
            throw new AuthorizationException("Only the dean of {$program->code}'s college can record this interview.");
        }
    }
}
