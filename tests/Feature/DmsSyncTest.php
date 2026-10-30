<?php

namespace Tests\Feature;

use App\Jobs\DeliverDmsEvent;
use App\Models\DmsOutboxEvent;
use App\Services\Dms\DmsClient;
use App\Services\Dms\DmsSync;
use App\Services\Dms\FakeDmsClient;
use App\Services\Dms\HttpDmsClient;
use App\Services\Enrollment\EnrollmentClient;
use App\Services\Enrollment\HttpEnrollmentClient;
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
        (new DeliverDmsEvent($event->id))->handle(app(DmsClient::class), app(EnrollmentClient::class));

        $this->assertCount($before, app(DmsClient::class)->received($event->applicant_ref));
    }

    /** FR-10/11: on admission the verified DMS versions are released as a packet; medical files never */
    public function test_admission_releases_an_applicant_packet(): void
    {
        $application = $this->app_($this->admitted());
        $this->assertSame(0, DmsOutboxEvent::where('type', 'packet.released')->count());   // nothing in the DMS: no release

        $application->documents()->get()->each(fn ($d, $i) => $d->update(['storage_driver' => 'dms', 'storage_ref' => "doc-{$i}:ver-{$i}"]));
        app(DmsSync::class)->admitted($application);

        $admission = $application->documents()->whereHas('requirement', fn ($q) => $q->where('stage', 'admission'))->where('status', 'verified')->count();
        $packet = collect(app(DmsClient::class)->received($application->number))->firstWhere('call', 'POST /applicant-packets')['body'];
        $this->assertSame($application->number, $packet['applicant_id']);
        $this->assertTrue($packet['released'] && $packet['admitted'] && $packet['verified']);
        $this->assertCount($admission, $packet['version_ids']);
        $this->assertGreaterThan(0, $admission);
        $this->assertLessThan($application->documents()->count(), $admission);   // medical files left out
    }

    public function test_dms_outage_never_blocks_staff_work(): void
    {
        $this->app->instance(DmsClient::class, new class implements DmsClient
        {
            public function postHistory(array $entry, string $idempotencyKey): void
            {
                throw new RuntimeException('DMS down');
            }

            public function postPacket(array $packet, string $idempotencyKey): void
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
            $job->handle(app(DmsClient::class), app(EnrollmentClient::class));
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

    public function test_http_drivers_send_bearer_and_idempotency_key(): void
    {
        Http::fake(['dms.test/*' => Http::response(['history_id' => 1], 201), 'enrollment.test/*' => Http::response([], 201)]);
        $dms = new HttpDmsClient('https://dms.test/api/v1', 't-1', 5);

        $dms->postHistory(['event_id' => 'evt-1', 'record_type' => 'applicant', 'record_id' => 'AD-2026-00001', 'revision' => 1, 'operation' => 'upsert', 'payload' => []], 'evt-1');
        $dms->postPacket(['event_id' => 'evt-2', 'applicant_id' => 'AD-2026-00001'], 'evt-2');
        (new HttpEnrollmentClient('https://enrollment.test/api/v1/admitted-applicants', 't-2', 5))->sendAdmitted(['reference_number' => 'AD-2026-00001'], 'evt-3');

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r->url() === 'https://dms.test/api/v1/history'
            && $r->hasHeader('Authorization', 'Bearer t-1') && $r->hasHeader('Idempotency-Key', 'evt-1'));
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://dms.test/api/v1/applicant-packets' && $r->hasHeader('Idempotency-Key', 'evt-2'));
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://enrollment.test/api/v1/admitted-applicants'
            && $r->hasHeader('Authorization', 'Bearer t-2') && $r->hasHeader('Idempotency-Key', 'evt-3') && $r['reference_number'] === 'AD-2026-00001');
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
