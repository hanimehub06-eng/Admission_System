<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\DmsOutboxEvent;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/**
 * Enrollment (FR-11, decision D12): no direct OAS → Enrollment call. The
 * DMS gets the Admitted flag; the student-number callback is off unless
 * the team confirms it, and then needs the "enrollment" API client.
 */
class EnrollmentTest extends TestCase
{
    use BuildsApplications;

    public function test_admission_completion_goes_to_the_dms(): void
    {
        $user = $this->admitted();
        $number = $this->number($user);

        $event = DmsOutboxEvent::where('type', 'admission.completed')->where('applicant_ref', $number)->firstOrFail();
        $this->assertSame(DmsOutboxEvent::SENT, $event->status);
        $this->assertTrue($event->payload['data']['admitted']);
        $this->assertSame('BSIT', $event->payload['data']['program']);
    }

    public function test_callback_is_off_by_default(): void
    {
        $number = $this->number($this->admitted());
        $this->postJson('/api/integrations/enrollment/student-number', ['admission_reference' => $number, 'student_number' => '2027-00123'],
            ['X-API-Key' => $this->client()])->assertNotFound();
    }

    public function test_student_number_callback_needs_the_enrollment_client(): void
    {
        config(['admission.enrollment.callback_enabled' => true]);
        $number = $this->number($this->admitted());
        $key = $this->client();
        $body = ['admission_reference' => $number, 'student_number' => '2027-00123'];

        $this->postJson('/api/integrations/enrollment/student-number', $body)->assertUnauthorized();
        $this->postJson('/api/integrations/enrollment/student-number', $body, ['X-API-Key' => 'wrong'])->assertUnauthorized();

        $this->postJson('/api/integrations/enrollment/student-number', $body, ['X-API-Key' => $key])
            ->assertOk()->assertJsonPath('data.student_number', '2027-00123');
        // Retried by Enrollment: same answer
        $this->postJson('/api/integrations/enrollment/student-number', $body, ['X-API-Key' => $key])->assertOk();

        $this->assertDatabaseHas('applications', ['number' => $number, 'student_number' => '2027-00123']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'api.inbound', 'actor_name' => 'API · enrollment']);
    }

    public function test_ip_allowlist_and_disabled_client(): void
    {
        config(['admission.enrollment.callback_enabled' => true]);
        $number = $this->number($this->admitted());
        $body = ['admission_reference' => $number, 'student_number' => '2027-00123'];

        $key = $this->client('--ips=10.0.0.5');
        $this->postJson('/api/integrations/enrollment/student-number', $body, ['X-API-Key' => $key])->assertUnauthorized();

        $key = $this->client();
        Artisan::call('api-client:create', ['name' => 'enrollment', '--disable' => true]);
        $this->postJson('/api/integrations/enrollment/student-number', $body, ['X-API-Key' => $key])->assertUnauthorized();
    }

    /** api-client:create, returning the printed key. Only its hash is stored. */
    private function client(?string $ips = null): string
    {
        $args = ['name' => 'enrollment'];
        if ($ips) {
            $args['--ips'] = substr($ips, 6);
        }
        Artisan::call('api-client:create', $args);
        preg_match('/X-API-Key: (\S+)/', Artisan::output(), $m);
        $this->assertSame(ApiClient::hashKey($m[1]), ApiClient::where('name', 'enrollment')->value('key_hash'));

        return $m[1];
    }
}
