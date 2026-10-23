<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\AdmissionPeriod;
use App\Models\Program;
use App\Models\Requirement;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Programs, slots, requirements and the admission period (FR-09). Every
 * change is written to the audit log, worded like the panel's own log.
 */
class AdmissionSetupService
{
    public function __construct(private AuditLogger $audit) {}

    public function setSlots(Program $program, int $slots): Program
    {
        return DB::transaction(function () use ($program, $slots) {
            $program = Program::lockForUpdate()->findOrFail($program->id);
            if ($program->slots === $slots) {
                return $program;
            }
            $filled = $program->filledSlots();
            if ($slots < $filled) {
                throw new BusinessRuleException("{$program->code} already has {$filled} accepted slots. Slots can't go below that.");
            }

            $this->audit->record('program.slots', "Changed {$program->code} slots from {$program->slots} to {$slots}", $program, meta: ['from' => $program->slots, 'to' => $slots]);
            $program->update(['slots' => $slots]);

            return $program;
        });
    }

    public function addProgram(string $code, string $name, int $slots): Program
    {
        $code = strtoupper(trim($code));
        if (Program::withTrashed()->where('code', $code)->exists()) {
            throw new BusinessRuleException("There is already a program with the code {$code}.");
        }

        $program = Program::create(['code' => $code, 'name' => trim($name), 'slots' => $slots]);
        $this->audit->record('program.added', "Added program {$code} · {$program->name} ({$slots} slots)", $program);

        return $program;
    }

    public function setRequirementActive(Requirement $requirement, bool $active): void
    {
        if ($requirement->is_active === $active) {
            return;
        }
        $requirement->update(['is_active' => $active]);
        $this->audit->record('requirement.toggled', ($active ? 'Required' : 'Stopped requiring')." \"{$requirement->title}\"", $requirement);
    }

    public function setDeadline(AdmissionPeriod $period, string $date): void
    {
        $deadline = Carbon::parse($date);
        if ($period->application_deadline->isSameDay($deadline)) {
            return;
        }
        $period->update(['application_deadline' => $deadline]);
        $this->audit->record('period.deadline', 'Changed the application deadline to '.$deadline->format('F j, Y'), $period);
    }
}
