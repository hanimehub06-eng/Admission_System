<?php

namespace App\Services\Enrollment;

use Illuminate\Support\Facades\Http;

/**
 * POST {ENROLLMENT_URL} with a Bearer token and an Idempotency-Key (School
 * Systems API Standard). Errors are thrown so the outbox job retries.
 */
class HttpEnrollmentClient implements EnrollmentClient
{
    public function __construct(
        private string $url,
        private string $token,
        private int $timeout,
    ) {}

    public function sendAdmitted(array $applicant, string $idempotencyKey): void
    {
        Http::withToken($this->token)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->post($this->url, $applicant)
            ->throw();
    }
}
