<?php

namespace App\Jobs;

use App\Models\DmsOutboxEvent;
use App\Services\Dms\DmsClient;
use App\Services\Enrollment\EnrollmentClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Delivers one outbox row: to the DMS (POST /history, or /applicant-packets
 * for packet.released) or, for enrollment.admitted, to the Enrollment
 * System. revision = the row id, which only grows. Retries with backoff; the same
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

    public function handle(DmsClient $dms, EnrollmentClient $enrollment): void
    {
        $event = DmsOutboxEvent::find($this->eventId);
        if (! $event || $event->status === DmsOutboxEvent::SENT) {
            return;
        }

        $event->increment('attempts');
        try {
            $key = ['event_id' => $event->event_id];
            match ($event->type) {
                'enrollment.admitted' => $enrollment->sendAdmitted($event->payload, $event->event_id),
                'packet.released' => $dms->postPacket($key + ['applicant_id' => $event->applicant_ref, 'revision' => $event->id] + $event->payload, $event->event_id),
                default => $dms->postHistory($key + [
                    'record_type' => $event->type === 'applicant.upserted' ? 'applicant' : $event->type,
                    'record_id' => $event->applicant_ref,
                    'revision' => $event->id,
                    'operation' => 'upsert',
                    'payload' => $event->payload,
                ], $event->event_id),
            };
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
