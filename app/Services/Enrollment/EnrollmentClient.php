<?php

namespace App\Services\Enrollment;

/**
 * Admission gives the Enrollment System each admitted applicant as JSON
 * (one POST per applicant, the payload in docs/openapi.json). Their API
 * isn't published yet: `fake` until it is, then `http` to ENROLLMENT_URL.
 */
interface EnrollmentClient
{
    public function sendAdmitted(array $applicant, string $idempotencyKey): void;
}
