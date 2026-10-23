<?php

namespace App\Enums;

/**
 * Application status set (FR-05, decision D8, plan §3.1). The first six
 * values match the keys the admission office UI already uses
 * (ADM.STATUSES); the rest come from the updated paper:
 *
 *   Draft → Submitted ⇄ Requires resubmission
 *   Submitted → For exam → Did not pass                                (final)
 *                        → Passed exam → For interview → Interview failed (final)
 *                                      ↘ (no interview required) ↘
 *                                        Offered (letter issued, deadline set)
 *   Offered → Slot accepted → For medical → Admitted                    (final)
 *   Offered → Forfeited                                                 (final)
 *
 * "passed" means the exam was passed; the result is released (and the
 * applicant moves on) separately.
 */
enum ApplicationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Resubmit = 'resubmit';
    case ForExam = 'for_exam';
    case Passed = 'passed';
    case Failed = 'failed';
    case ForInterview = 'for_interview';
    case InterviewFailed = 'interview_failed';
    case Offered = 'offered';
    case SlotAccepted = 'slot_accepted';
    case ForMedical = 'for_medical';
    case Admitted = 'admitted';
    case Forfeited = 'forfeited';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Resubmit => 'Requires resubmission',
            self::ForExam => 'For exam',
            self::Passed => 'Passed exam',
            self::Failed => 'Did not pass',
            self::ForInterview => 'For interview',
            self::InterviewFailed => 'Interview failed',
            self::Offered => 'Offered',
            self::SlotAccepted => 'Slot accepted',
            self::ForMedical => 'For medical',
            self::Admitted => 'Admitted',
            self::Forfeited => 'Forfeited',
        };
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::Resubmit, self::ForExam],
            self::Resubmit => [self::Submitted, self::ForExam],
            self::ForExam => [self::Passed, self::Failed],
            // A result can be corrected before release; release moves a pass on
            self::Passed => [self::Failed, self::ForInterview, self::Offered],
            self::Failed => [self::Passed],
            self::ForInterview => [self::Offered, self::InterviewFailed],
            self::Offered => [self::SlotAccepted, self::Forfeited],
            self::SlotAccepted => [self::ForMedical],
            self::ForMedical => [self::Admitted],
            self::InterviewFailed, self::Admitted, self::Forfeited => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isLocked(): bool
    {
        return $this !== self::Draft;
    }

    /** The offer was accepted: the slot is taken (slots are counted from these). */
    public function holdsSlot(): bool
    {
        return in_array($this, [self::SlotAccepted, self::ForMedical, self::Admitted], true);
    }

    /** @return list<string> */
    public static function slotHolding(): array
    {
        return array_map(fn (self $s) => $s->value, array_values(array_filter(self::cases(), fn (self $s) => $s->holdsSlot())));
    }
}
