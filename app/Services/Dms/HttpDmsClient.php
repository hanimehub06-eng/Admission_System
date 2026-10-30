<?php

namespace App\Services\Dms;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The real DMS: Bearer token, base URL ending in /api/v1 (DMS openapi.json).
 * Errors are thrown so the outbox job retries with the same key.
 */
class HttpDmsClient implements DmsClient
{
    public function __construct(
        private string $baseUrl,
        private string $token,
        private int $timeout,
    ) {}

    public function postHistory(array $entry, string $idempotencyKey): void
    {
        $this->client($idempotencyKey)->post('/history', $entry)->throw();
    }

    public function postPacket(array $packet, string $idempotencyKey): void
    {
        $this->client($idempotencyKey)->post('/applicant-packets', $packet)->throw();
    }

    private function client(string $idempotencyKey): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);
    }
}
