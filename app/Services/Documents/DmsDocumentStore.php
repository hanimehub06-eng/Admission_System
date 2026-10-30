<?php

namespace App\Services\Documents;

use App\Models\Application;
use App\Models\Requirement;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Files go straight to the Document Management System (paper §9.1). The
 * OAS keeps "{document_id}:{version_id}" (DMS UUIDs). DMS openapi.json:
 *   POST /documents                    → { document_id, version_id }  first upload
 *   POST /documents/{id}/versions      → same, needs the document's revision
 *   GET  /documents/{id}               → { data: { revision } }
 *   GET  /files/{version_id}           → the file (423 until its virus scan is clean)
 * Medical files never come here: the DMS refuses them (DocumentStorage keeps them local).
 */
class DmsDocumentStore implements DocumentStore
{
    public function __construct(
        private string $baseUrl,
        private string $token,
        private int $timeout,
    ) {}

    public function driver(): string
    {
        return 'dms';
    }

    public function put(UploadedFile $file, Application $application, Requirement $requirement, ?string $previousRef = null): StoredFile
    {
        $checksum = hash_file('sha256', $file->getRealPath());
        $document = $previousRef ? explode(':', $previousRef)[0] : null;
        $fields = $document
            ? ['revision' => (int) $this->client()->get('/documents/'.rawurlencode($document))->throw()->json('data.revision')]
            : [
                'title' => $requirement->title.' · '.$application->number,
                'classification' => 'Confidential',
                'reference' => $application->number,
                'category' => $requirement->key,
            ];

        $response = $this->client()
            // The same file for the same requirement is stored once, even if the call is retried
            ->withHeaders(['Idempotency-Key' => hash('sha256', $application->number.'|'.$requirement->key.'|'.$checksum)])
            ->attach('file', fopen($file->getRealPath(), 'r'), $file->getClientOriginalName())
            ->post($document ? '/documents/'.rawurlencode($document).'/versions' : '/documents', $fields);

        $body = $response->throw()->json();
        if (empty($body['document_id']) || empty($body['version_id'])) {
            throw new RuntimeException('The document system did not return a document ID.');
        }

        return new StoredFile('dms', $body['document_id'].':'.$body['version_id'], $checksum);
    }

    /** Opened through an authorized OAS route only: the DMS token never reaches the browser. */
    public function readStream(string $ref)
    {
        $version = explode(':', $ref)[1] ?? $ref;
        $response = $this->client()->withOptions(['stream' => true])->get('/files/'.rawurlencode($version))->throw();

        return $response->toPsrResponse()->getBody()->detach();
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->acceptJson()
            ->timeout($this->timeout)
            ->retry(3, 500, throw: false);
    }
}
