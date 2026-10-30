<?php

namespace App\Services\Dms;

/**
 * Record sync with the Document Management System (INT-01), following the
 * DMS openapi.json (School Systems API Standard, docs/API_Guide.md).
 * `fake` (local files) until the DMS token exists, then `http`.
 *
 * Never send passwords, 2FA secrets or tokens (§8.3).
 */
interface DmsClient
{
    /** POST /history: one record change (event_id, record_type, record_id, revision, operation, payload). */
    public function postHistory(array $entry, string $idempotencyKey): void;

    /** POST /applicant-packets: the exact document versions released for Enrollment. */
    public function postPacket(array $packet, string $idempotencyKey): void;
}
