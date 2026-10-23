<?php

namespace App\Services\Dms;

use Illuminate\Support\Facades\Storage;

/**
 * Stand-in DMS for development and tests: writes each call to
 * dms-fake/{ref}.jsonl on the private disk. An Idempotency-Key it has
 * already seen is ignored, as the real DMS should do.
 */
class FakeDmsClient implements DmsClient
{
    public function __construct(private string $disk) {}

    public function putApplicant(string $ref, array $record, string $idempotencyKey): void
    {
        $this->write($ref, 'PUT /applicants/'.$ref, $record, $idempotencyKey);
    }

    public function postEvent(string $ref, array $event, string $idempotencyKey): void
    {
        $this->write($ref, 'POST /applicants/'.$ref.'/events', $event, $idempotencyKey);
    }

    /** Everything the fake DMS received for an applicant (tests, `dms:outbox`). */
    public function received(string $ref): array
    {
        $file = $this->path($ref);
        if (! Storage::disk($this->disk)->exists($file)) {
            return [];
        }

        return array_values(array_map(
            fn ($line) => json_decode($line, true),
            array_filter(explode("\n", Storage::disk($this->disk)->get($file))),
        ));
    }

    private function write(string $ref, string $call, array $body, string $key): void
    {
        foreach ($this->received($ref) as $entry) {
            if ($entry['idempotency_key'] === $key) {
                return;
            }
        }
        Storage::disk($this->disk)->append($this->path($ref), json_encode([
            'call' => $call, 'idempotency_key' => $key, 'body' => $body,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function path(string $ref): string
    {
        return 'dms-fake/'.preg_replace('/[^A-Za-z0-9-]/', '', $ref).'.jsonl';
    }
}
