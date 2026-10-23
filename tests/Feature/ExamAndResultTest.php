<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\Role;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/** Phases 6–7: exam administration (FR-06) and results (FR-08, NFR-02). */
class ExamAndResultTest extends TestCase
{
    use BuildsApplications;

    public function test_create_session(): void
    {
        $body = ['date' => '2026-12-05', 'batch' => 'A', 'venue' => 'Main Building · Room 301', 'capacity' => 30];
        $this->asUser($this->officer())->postJson('/api/admin/exam-sessions', $body)
            ->assertCreated()->assertJsonPath('data.batch', 'A')->assertJsonPath('data.capacity', 30);
        $this->asUser($this->officer())->postJson('/api/admin/exam-sessions', $body)->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['description' => 'Created exam session Batch A · Sat, Dec 5 at Main Building · Room 301 (30 seats)']);
    }

    /** FR-06: approval needs verified documents and a free seat; it issues the permit */
    public function test_approve_for_exam(): void
    {
        $user = $this->submitted();
        $number = $this->number($user);
        $session = $this->examSession(capacity: 1);

        $this->asUser($this->officer())->postJson("/api/admin/applicants/{$number}/approve", ['sessionId' => 'S'.$session->id])
            ->assertUnprocessable()->assertJsonPath('message', 'Verify every required document before approving for the exam.');

        $this->asUser($this->officer())->postJson("/api/admin/applicants/{$number}/documents/verify-pending")->assertOk();
        $this->asUser($this->officer())->postJson("/api/admin/applicants/{$number}/approve", ['sessionId' => 'S'.$session->id])
            ->assertOk()
            ->assertJsonPath('data.exam.sessionId', 'S'.$session->id)
            ->assertJsonPath('data.exam.permit', 'EX-27-'.substr($number, -5));
        $this->assertSame(ApplicationStatus::ForExam, $this->app_($user)->status);

        $this->asUser($user)->getJson('/api/applicant/portal')
            ->assertJsonPath('data.phase', 'exam')
            ->assertJsonPath('data.exam.date', '2026-12-05')
            ->assertJsonPath('data.exam.time', '8:00 AM – 12:00 NN')
            ->assertJsonPath('data.exam.permit', 'EX-27-'.substr($number, -5));

        // The session is now full
        $other = $this->submitted('other@example.com');
        $this->asUser($this->officer())->postJson("/api/admin/applicants/{$this->number($other)}/documents/verify-pending");
        $this->asUser($this->officer())->postJson("/api/admin/applicants/{$this->number($other)}/approve", ['sessionId' => 'S'.$session->id])
            ->assertUnprocessable()->assertJsonPath('message', 'That session is full. Pick another one.');
    }

    public function test_attendance_needs_checks_and_reschedule_resets_it(): void
    {
        $user = $this->approved();
        $number = $this->number($user);

        $this->asUser($this->officer())->patchJson("/api/admin/applicants/{$number}/exam", ['attendance' => 'present'])->assertUnprocessable();
        $this->asUser($this->officer())->patchJson("/api/admin/applicants/{$number}/exam", ['attendance' => 'absent'])
            ->assertOk()->assertJsonPath('data.exam.attendance', 'absent');

        $later = $this->examSession(date: '2026-12-12', batch: 'B');
        $this->asUser($this->officer())->postJson("/api/admin/applicants/{$number}/reschedule", ['sessionId' => 'S'.$later->id])
            ->assertOk()->assertJsonPath('data.exam.sessionId', 'S'.$later->id)->assertJsonPath('data.exam.attendance', null);

        $this->asUser($this->officer())->patchJson("/api/admin/applicants/{$number}/exam", ['permitChecked' => true, 'formChecked' => true, 'attendance' => 'present'])
            ->assertOk()->assertJsonPath('data.exam.attendance', 'present');
        $this->assertDatabaseHas('audit_logs', ['description' => 'Marked present at the exam venue (permit and form checked)']);
    }

    /** FR-08: record, correct before release, release */
    public function test_record_and_release_result(): void
    {
        $user = $this->examined('failed', 55);
        $number = $this->number($user);

        // Correct before release
        $this->asUser($this->officer())->postJson("/api/admin/applicants/{$number}/result", ['score' => 85, 'decision' => 'passed'])
            ->assertOk()->assertJsonPath('data.result.decision', 'passed')->assertJsonPath('data.result.released', false);
        $this->assertSame(ApplicationStatus::Passed, $this->app_($user)->status);

        // Unreleased: the applicant sees nothing about it
        $this->asUser($user)->getJson('/api/applicant/portal')->assertJsonPath('data.phase', 'exam')->assertJsonPath('data.result', null);

        $this->asUser($this->officer())->postJson('/api/admin/results/release', ['ids' => [$number]])
            ->assertOk()->assertJsonPath('released', 1);
        $this->asUser($this->officer())->postJson("/api/admin/applicants/{$number}/result", ['score' => 10, 'decision' => 'failed'])->assertUnprocessable();

        $this->asUser($user)->getJson('/api/applicant/portal')
            ->assertJsonPath('data.phase', 'result')
            ->assertJsonPath('data.result.decision', 'passed');
        $this->assertDatabaseHas('audit_logs', ['description' => 'Released 1 exam result through the system', 'application_id' => null]);
    }

    /** NFR-02: no applicant response ever carries the score */
    public function test_score_never_reaches_the_applicant(): void
    {
        $user = $this->examined('passed', 87);
        $this->asUser($this->officer())->postJson('/api/admin/results/release', ['ids' => [$this->number($user)]]);

        $responses = [
            $this->asUser($user)->getJson('/api/applicant/portal'),
            $this->asUser($user)->postJson('/api/applicant/offer/accept'),
            $this->asUser($user)->getJson('/api/auth/me'),
        ];
        foreach ($responses as $response) {
            $response->assertOk();
            $this->assertStringNotContainsString('score', strtolower($response->getContent()));
            $this->assertStringNotContainsString('percentile', strtolower($response->getContent()));
        }

        // Staff: admission sees the score; the clinic can't load the office state, and its own has no score
        $this->asUser($this->officer())->getJson('/api/admin/state')->assertJsonPath('data.applicants.0.result.score', 87);
        $clinic = $this->makeUser(Role::Clinic);
        $this->asUser($clinic)->getJson('/api/admin/state')->assertForbidden();
        $clinicState = $this->asUser($clinic)->getJson('/api/clinic/state')->assertOk();
        $this->assertStringNotContainsString('score', strtolower($clinicState->streamedContent()));
    }

    public function test_accept_offer(): void
    {
        $user = $this->examined('passed');
        $this->asUser($user)->postJson('/api/applicant/offer/accept')->assertUnprocessable();

        $this->asUser($this->officer())->postJson('/api/admin/results/release', ['ids' => [$this->number($user)]]);
        $this->asUser($user)->postJson('/api/applicant/offer/accept')->assertOk()->assertJsonPath('data.accepted', true);
    }

    /** NFR-01: the clinic can't run exams or record results */
    public function test_clinic_cannot_manage_exams(): void
    {
        $clinic = $this->makeUser(Role::Clinic);
        $this->asUser($clinic)->postJson('/api/admin/results/release', ['ids' => ['AD-2026-00001']])->assertForbidden();
        $this->asUser($clinic)->postJson('/api/admin/exam-sessions', [])->assertForbidden();
    }
}
