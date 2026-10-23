<?php

namespace Tests\Feature;

use App\Jobs\DeliverDmsEvent;
use App\Models\DmsOutboxEvent;
use App\Services\Dms\DmsClient;
use App\Services\Dms\FakeDmsClient;
use App\Services\Dms\HttpDmsClient;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/** INT-01: every record change reaches the DMS through the outbox, once. */
class DmsSyncTest extends TestCase
{
    use BuildsApplications;

    public function test_changes_are_delivered_to_the_fake_dms(): void
    {
        $user = $this->released();
        $number = $this->number($user);

        $types = DmsOutboxEvent::where('applicant_ref', $number)->orderBy('id')->pluck('type')->all();
        foreach (['applicant.upserted', 'document.uploaded', 'status.changed', 'document.reviewed', 'exam.scheduled', 'exam.attendance', 'exam.result'] as $type) {
            $this->assertContains($type, $types);
        }
        $this->assertSame(0, DmsOutboxEvent::where('status', '!=', DmsOutboxEvent::SENT)->count());

        $received = app(DmsClient::class)->received($number);
        $this->assertCount(DmsOutboxEvent::where('applicant_ref', $number)->count(), $received);

        // Restricted values are labelled; credentials never leave the OAS
        $result = DmsOutboxEvent::where('type', 'exam.result')->first()->payload['data'];
        $this->assertSame('restricted', $result['score']['classification']);
        $record = DmsOutboxEvent::where('type', 'applicant.upserted')->latest('id')->first()->payload;
        $this->assertSame($number, $record['applicant_ref']);
        foreach (['password', 'two_factor_secret', 'remember_token'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($received));
        }
    }

    public function test_redelivery_is_idempotent(): void
    {
        $user = $this->makeApplicant();
        $event = DmsOutboxEvent::firstOrFail();
        $before = count(app(DmsClient::class)->received($event->applicant_ref));

        $event->update(['status' => DmsOutboxEvent::PENDING]);
        (new DeliverDmsEvent($event->id))->handle(app(DmsClient::class));

        $this->assertCount($before, app(DmsClient::class)->received($event->applicant_ref));
    }

    public function test_dms_outage_never_blocks_staff_work(): void
    {
        $this->app->instance(DmsClient::class, new class implements DmsClient
        {
            public function putApplicant(string $ref, array $record, string $idempotencyKey): void
            {
                throw new RuntimeException('DMS down');
            }

            public function postEvent(string $ref, array $event, string $idempotencyKey): void
            {
                throw new RuntimeException('DMS down');
            }
        });
        config(['queue.default' => 'database']);   // a real queue, as in production

        $user = $this->submitted();
        $this->assertSame('submitted', $this->app_($user)->status->value);
        $this->assertGreaterThan(0, DmsOutboxEvent::where('status', DmsOutboxEvent::PENDING)->count());

        // The job gives up after its tries: the event is kept as failed for --retry
        $event = DmsOutboxEvent::first();
        $job = new DeliverDmsEvent($event->id);
        try {
            $job->handle(app(DmsClient::class));
        } catch (RuntimeException) {
            $job->failed(new RuntimeException('DMS down'));
        }
        $this->assertSame(DmsOutboxEvent::FAILED, $event->fresh()->status);
        $this->assertSame('DMS down', $event->fresh()->last_error);

        $this->app->instance(DmsClient::class, new FakeDmsClient('local'));
        config(['queue.default' => 'sync']);
        Artisan::call('dms:outbox', ['--retry' => true]);
        $this->assertSame(DmsOutboxEvent::SENT, $event->fresh()->status);
    }

    public function test_http_driver_sends_the_key_and_idempotency_key(): void
    {
        Http::fake(['dms.test/*' => Http::response(['ok' => true])]);
        $client = new HttpDmsClient('https://dms.test/api', 'k-1', 5);

        $client->putApplicant('AD-2026-00001', ['a' => 1], 'evt-1');
        $client->postEvent('AD-2026-00001', ['type' => 'status.changed'], 'evt-2');

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && $r->url() === 'https://dms.test/api/applicants/AD-2026-00001'
            && $r->hasHeader('X-API-Key', 'k-1') && $r->hasHeader('Idempotency-Key', 'evt-1'));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/applicants/AD-2026-00001/events')
            && $r->hasHeader('Idempotency-Key', 'evt-2'));
    }

    public function test_resync_command(): void
    {
        $user = $this->makeApplicant();
        $number = $this->number($user);
        $count = DmsOutboxEvent::count();

        $this->artisan('dms:outbox', ['--resync' => $number])->assertSuccessful();
        $this->assertSame($count + 2, DmsOutboxEvent::count());
        $this->artisan('dms:outbox')->assertSuccessful();
    }
}
