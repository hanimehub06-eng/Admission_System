<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Application;
use App\Models\Program;
use App\Models\User;
use App\Notifications\AdmissionNotice;
use App\Services\Dms\DmsSync;
use Illuminate\Support\Facades\DB;

/**
 * Acceptance letter and slot acceptance (FR-09, D14, D16).
 *
 * Final pass (exam, plus interview where the program requires one) →
 * Offered: the first-choice program is offered with a deadline from the
 * period's acceptance window. The applicant accepts before the deadline;
 * the slot is taken only then, under a row lock, so a program never goes
 * over its slots. Unaccepted offers are forfeited by the scheduler.
 */
class OfferService
{
    public function __construct(
        private ApplicationStatusService $status,
        private AuditLogger $audit,
        private DmsSync $dms,
    ) {}

    /** After a passing result is released: interview first if the program requires one (D14). */
    public function afterResultReleased(Application $application, ?User $by): void
    {
        if ($application->status !== ApplicationStatus::Passed) {
            return;
        }
        $program = $application->firstChoice();
        if ($program?->requires_interview) {
            $this->status->transition($application, ApplicationStatus::ForInterview, $by, "{$program->code} requires an interview");

            return;
        }
        $this->offer($application, $by);
    }

    /** Issue the offer (acceptance letter) for the first-choice program. */
    public function offer(Application $application, ?User $by): void
    {
        $program = $application->firstChoice()
            ?? throw new BusinessRuleException('The applicant has no program choice to offer.');
        $days = $application->period->acceptance_window_days ?: config('admission.offers.default_window_days');
        $deadline = now()->setTimezone(config('admission.display_timezone'))->addDays($days)->endOfDay()->utc();

        $application->fill([
            'offered_program_id' => $program->id,
            'offered_at' => now(),
            'acceptance_deadline' => $deadline,
        ]);
        $this->status->transition($application, ApplicationStatus::Offered, $by, "Offered {$program->code}");
        $this->audit->record('offer.issued', "Issued the acceptance letter for {$program->code} · accept by ".$this->localDate($deadline), $application, $application);
    }

    /**
     * The applicant accepts the slot. The caller has already checked the
     * email code when 2FA at acceptance is on (NFR-04).
     */
    public function accept(Application $application, User $applicant): void
    {
        if ($application->status->holdsSlot()) {
            return; // accepted already (double click)
        }
        if ($application->status === ApplicationStatus::Forfeited) {
            throw new BusinessRuleException('The acceptance deadline passed and this offer was forfeited. Contact the admission office.');
        }
        if ($application->status !== ApplicationStatus::Offered) {
            throw new BusinessRuleException('There is no offer of admission to accept yet.');
        }
        if ($application->acceptance_deadline?->isPast()) {
            throw new BusinessRuleException('The acceptance deadline has passed.');
        }

        DB::transaction(function () use ($application, $applicant) {
            $program = Program::lockForUpdate()->findOrFail($application->offered_program_id);
            $taken = Application::where('offered_program_id', $program->id)
                ->whereIn('status', ApplicationStatus::slotHolding())->count();
            if ($taken >= $program->slots) {
                throw new BusinessRuleException("All {$program->code} slots have been taken. Contact the admission office.");
            }

            $application->offer_accepted_at = now();
            $this->status->transition($application, ApplicationStatus::SlotAccepted, $applicant, "Accepted the {$program->code} slot");
            $this->status->transition($application, ApplicationStatus::ForMedical, $applicant);
            $this->audit->record('offer.accepted', "Accepted the offer of admission · {$program->code} slot", $application, $application);
            $this->dms->record('offer.accepted', $application, ['program' => $program->code, 'accepted_at' => $application->offer_accepted_at->toIso8601String()]);
        });
    }

    /** Scheduler: close offers whose deadline passed (no slot to release: slots are taken on acceptance). */
    public function forfeitExpired(): int
    {
        $expired = Application::where('status', ApplicationStatus::Offered)
            ->where('acceptance_deadline', '<', now())
            ->with('applicant.user')->get();

        foreach ($expired as $application) {
            DB::transaction(function () use ($application) {
                $application->forfeited_at = now();
                $this->status->transition($application, ApplicationStatus::Forfeited, null, 'Acceptance deadline passed');
                $this->audit->record('offer.forfeited', 'Offer forfeited: not accepted by the deadline', $application, $application);
                $this->dms->record('offer.forfeited', $application, ['deadline' => $application->acceptance_deadline->toIso8601String()]);
            });
        }

        return $expired->count();
    }

    /** Scheduler: one reminder when the deadline is close. */
    public function remindDeadlines(): int
    {
        $soon = Application::where('status', ApplicationStatus::Offered)
            ->whereNull('acceptance_reminded_at')
            ->whereBetween('acceptance_deadline', [now(), now()->addDays(config('admission.offers.reminder_days_before'))])
            ->with('applicant.user')->get();

        foreach ($soon as $application) {
            $application->applicant->user->notify(new AdmissionNotice('offer_reminder', 'Reminder: accept your slot',
                'Your offer of admission is waiting. Accept your slot in the applicant portal by '.$this->localDate($application->acceptance_deadline).', or it will be forfeited.',
                $application->number));
            $application->update(['acceptance_reminded_at' => now()]);
        }

        return $soon->count();
    }

    public function localDate($at): string
    {
        return $at->copy()->setTimezone(config('admission.display_timezone'))->format('F j, Y');
    }
}
