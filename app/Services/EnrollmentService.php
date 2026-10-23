<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Application;
use App\Services\Dms\DmsSync;

/**
 * Enrollment (FR-11, decision D12). The paper has no direct OAS →
 * Enrollment connection: the OAS marks applicants Admitted in the DMS and
 * Enrollment reads them from there. The one possible link back, Enrollment
 * returning the student number, is built behind
 * ENROLLMENT_CALLBACK_ENABLED until the team confirms it.
 */
class EnrollmentService
{
    public function __construct(
        private AuditLogger $audit,
        private DmsSync $dms,
    ) {}

    public function callbackEnabled(): bool
    {
        return (bool) config('admission.enrollment.callback_enabled');
    }

    /** Enrollment's callback: the student number for an admitted applicant. Idempotent. */
    public function acknowledge(Application $application, string $studentNumber): Application
    {
        if ($application->status !== ApplicationStatus::Admitted) {
            throw new BusinessRuleException('This applicant is not admitted.');
        }
        if ($application->student_number === $studentNumber) {
            return $application;
        }
        if ($application->student_number) {
            throw new BusinessRuleException("This applicant already has student number {$application->student_number}.");
        }

        $application->update(['student_number' => $studentNumber]);
        $this->audit->record('enrollment.acknowledged', "Enrollment assigned student number {$studentNumber}", $application, $application);
        $this->dms->record('applicant.upserted', $application);

        return $application;
    }
}
