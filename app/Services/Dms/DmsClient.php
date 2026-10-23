<?php

namespace App\Services\Dms;

/**
 * Record sync with the Document Management System (paper §9.3, INT-01).
 * The DMS is the system of record; MySQL here is the working copy. The API
 * is "to be agreed with the DMS team", so callers only use this interface:
 * `fake` (local files) until the contract exists, then `http`.
 *
 * Never send passwords, 2FA secrets or tokens (§8.3).
 */
interface DmsClient
{
    /** PUT /applicants/{ref}: the applicant's current record. */
    public function putApplicant(string $ref, array $record, string $idempotencyKey): void;

    /** POST /applicants/{ref}/events: one change (status, document, result, admitted…). */
    public function postEvent(string $ref, array $event, string $idempotencyKey): void;
}
