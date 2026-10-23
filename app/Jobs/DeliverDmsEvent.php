<?php

namespace App\Jobs;

use App\Models\DmsOutboxEvent;
use App\Services\Dms\DmsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Delivers one outbox row to the DMS. Retries with backoff; the same
 * Idempotency-Key (event_id) is sent every time. After the last try the
 * row is marked failed: `php artisan dms:outbox --retry` sends it again.
 */
class DeliverDmsEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public function __construct(public int $eventId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900, 3600];
    }

    public function handle(DmsClient $dms): void
    {
        $event = DmsOutboxEvent::find($this->eventId);
        if (! $event || $event->status === DmsOutboxEvent::SENT) {
            return;
        }

        $event->increment('attempts');
        try {
            $event->type === 'applicant.upserted'
                ? $dms->putApplicant($event->applicant_ref, $event->payload, $event->event_id)
                : $dms->postEvent($event->applicant_ref, $event->payload, $event->event_id);
        } catch (Throwable $e) {
            $event->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e;
        }

        $event->update(['status' => DmsOutboxEvent::SENT, 'sent_at' => now(), 'last_error' => null]);
    }

    public function failed(?Throwable $e): void
    {
        DmsOutboxEvent::whereKey($this->eventId)->where('status', '!=', DmsOutboxEvent::SENT)->update([
            'status' => DmsOutboxEvent::FAILED,
            'last_error' => mb_substr((string) $e?->getMessage(), 0, 500) ?: null,
        ]);
    }
}
