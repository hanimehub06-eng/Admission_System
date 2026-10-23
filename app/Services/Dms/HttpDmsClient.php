<?php

namespace App\Services\Dms;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The real DMS over HTTPS + X-API-Key (paper §9.3, proposed contract).
 * Adjust the paths here only, once the DMS team confirms them. Errors are
 * thrown so the outbox job retries with the same key.
 */
class HttpDmsClient implements DmsClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private int $timeout,
    ) {}

    public function putApplicant(string $ref, array $record, string $idempotencyKey): void
    {
        $this->client($idempotencyKey)->put('/applicants/'.rawurlencode($ref), $record)->throw();
    }

    public function postEvent(string $ref, array $event, string $idempotencyKey): void
    {
        $this->client($idempotencyKey)->post('/applicants/'.rawurlencode($ref).'/events', $event)->throw();
    }

    private function client(string $idempotencyKey): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['X-API-Key' => $this->apiKey, 'Idempotency-Key' => $idempotencyKey])
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);
    }
}
