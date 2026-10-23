<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\ExamRegistration;
use App\Notifications\AdmissionNotice;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/** FR-07: QR permit, printable form, check-in scan; FR-12 exam reminder. */
class ExamPermitTest extends TestCase
{
    use BuildsApplications;

    public function test_permit_and_form_pdfs(): void
    {
        $user = $this->submitted();
        $this->asUser($user)->get('/api/applicant/exam-permit')->assertStatus(422);
        $this->asUser($user)->get('/api/applicant/application-form')->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $user = $this->approved('other@example.com');
        $response = $this->asUser($user)->get('/api/applicant/exam-permit')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_qr_check_in(): void
    {
        $this->travelTo('2026-12-05 08:30'); // exam day (session date in examSession())
        $user = $this->approved();
        $registration = ExamRegistration::firstOrFail();
        $this->assertSame(40, strlen($registration->qr_token));
        $other = $this->examSession(30, '2026-12-05', 'B');

        $this->asUser($this->officer())->postJson('/api/admin/exam/check-in', ['token' => 'nope'])->assertStatus(422);
        $this->asUser($this->officer())->postJson('/api/admin/exam/check-in', ['token' => $registration->qr_token, 'sessionId' => 'S'.$other->id])
            ->assertStatus(422);
        $this->asUser($this->makeUser(Role::Clinic))->postJson('/api/admin/exam/check-in', ['token' => $registration->qr_token])->assertForbidden();

        $this->asUser($this->officer())->postJson('/api/admin/exam/check-in', ['token' => $registration->qr_token])
            ->assertOk()->assertJsonPath('data.exam.attendance', 'present')->assertJsonPath('data.exam.permitChecked', true);
        $this->asUser($this->officer())->postJson('/api/admin/exam/check-in', ['token' => $registration->qr_token])->assertOk(); // scanned twice
        $this->assertSame(1, AuditLog::where('action', 'exam.attendance')->count());
    }

    public function test_check_in_only_on_the_exam_day(): void
    {
        $this->travelTo('2026-12-01 09:00');
        $this->approved();
        $this->asUser($this->officer())->postJson('/api/admin/exam/check-in', ['token' => ExamRegistration::first()->qr_token])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'December 5, 2026'));
    }

    public function test_reminder_the_day_before(): void
    {
        Notification::fake();
        $this->travelTo('2026-12-04 07:00');
        $user = $this->approved();

        Artisan::call('admission:deadlines', ['--only' => 'exam-reminders']);
        Artisan::call('admission:deadlines', ['--only' => 'exam-reminders']);
        // Ran twice, sent once
        $this->assertCount(1, Notification::sent($user, AdmissionNotice::class, fn ($n) => $n->kind === 'exam_reminder'));
    }

    /** D17: self-scheduling is off unless switched on */
    public function test_self_scheduling_flag(): void
    {
        $this->travelTo('2026-11-20 09:00');
        $user = $this->submitted();
        $session = $this->examSession();
        $this->asUser($user)->getJson('/api/applicant/exam-sessions')->assertNotFound();

        config(['admission.exams.self_schedule' => true]);
        $this->asUser($user)->getJson('/api/applicant/exam-sessions')->assertOk()->assertJsonPath('data.0.id', 'S'.$session->id);
        // Documents not verified yet
        $this->asUser($user)->postJson('/api/applicant/exam-session', ['sessionId' => 'S'.$session->id])->assertStatus(422);
        $this->asUser($this->officer())->postJson("/api/admin/applicants/{$this->number($user)}/documents/verify-pending")->assertOk();
        $this->asUser($user)->postJson('/api/applicant/exam-session', ['sessionId' => 'S'.$session->id])
            ->assertOk()->assertJsonPath('data.status', 'for_exam');
    }
}
