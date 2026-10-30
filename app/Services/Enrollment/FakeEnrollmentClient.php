<?php

namespace App\Services\Enrollment;

use Illuminate\Support\Facades\Storage;

/** Stand-in until the Enrollment System's endpoint exists: appends each applicant to enrollment-fake.jsonl. */
class FakeEnrollmentClient implements EnrollmentClient
{
    public const FILE = 'enrollment-fake.jsonl';

    public function __construct(private string $disk) {}

    public function sendAdmitted(array $applicant, string $idempotencyKey): void
    {
        Storage::disk($this->disk)->append(self::FILE, json_encode(
            ['idempotency_key' => $idempotencyKey, 'body' => $applicant], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }
}
