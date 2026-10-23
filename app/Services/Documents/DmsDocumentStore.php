<?php

namespace App\Services\Documents;

use App\Models\Application;
use App\Models\Requirement;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Files go straight to the Document Management System (paper §9.1:
 * synchronous upload; the OAS keeps only the DMS document ID).
 *
 * Proposed contract (§9.3, to be confirmed by the DMS team):
 *   POST /documents                     → { id }   first upload
 *   POST /documents/{id}/versions       → { id }   replacement after "Requires resubmission"
 *   GET  /documents/{id}/download-url   → { url }  link valid for 5 minutes
 */
class DmsDocumentStore implements DocumentStore
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private int $timeout,
    ) {}

    public function driver(): string
    {
        return 'dms';
    }

    public function put(UploadedFile $file, Application $application, Requirement $requirement, ?string $previousRef = null): StoredFile
    {
        $checksum = hash_file('sha256', $file->getRealPath());
        $path = $previousRef ? '/documents/'.rawurlencode($previousRef).'/versions' : '/documents';

        $response = $this->client()
            // The same file for the same requirement is stored once, even if the call is retried
            ->withHeaders(['Idempotency-Key' => hash('sha256', $application->number.'|'.$requirement->key.'|'.$checksum)])
            ->attach('file', fopen($file->getRealPath(), 'r'), $file->getClientOriginalName())
            ->post($path, [
                'source' => 'admission',
                'applicant_ref' => $application->number,
                'category' => $requirement->key,
                'confidential' => $requirement->stage === Requirement::STAGE_MEDICAL ? '1' : '0',
                'sha256' => $checksum,
            ]);

        $id = $response->throw()->json('id');
        if (! $id) {
            throw new RuntimeException('The document system did not return a document ID.');
        }

        return new StoredFile('dms', (string) $id, $checksum);
    }

    /** Opened through an authorized OAS route only: the 5-minute link never reaches the browser. */
    public function readStream(string $ref)
    {
        $url = $this->client()->get('/documents/'.rawurlencode($ref).'/download-url')->throw()->json('url');
        if (! $url) {
            throw new RuntimeException('The document system did not return a download link.');
        }
        $response = Http::timeout($this->timeout)->withOptions(['stream' => true])->get($url)->throw();

        return $response->toPsrResponse()->getBody()->detach();
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['X-API-Key' => $this->apiKey])
            ->acceptJson()
            ->timeout($this->timeout)
            ->retry(3, 500, throw: false);
    }
}
