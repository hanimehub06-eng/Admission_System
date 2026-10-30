<?php

use App\Enums\Role;
use App\Models\College;
use App\Models\User;

/** A personnel account given a task by the Admission Head. */
function tasked(string $task)
{
    $user = User::factory()->create(['email' => "{$task}@plpasig.edu.ph"])->assignRole(Role::Admission->value);
    $user->forceFill(['task' => $task])->save();

    return $user;
}

function deanOf(string $code)
{
    $dean = User::factory()->create(['email' => "dean.{$code}@plpasig.edu.ph"])->assignRole(Role::CollegeDean->value);
    College::where('code', $code)->update(['dean_user_id' => $dean->id]);

    return $dean;
}

// 1.1
test('the applicant ID is issued only when admission approves the application', function () {
    $user = $this->submitted();
    expect($this->app_($user)->applicant_no)->toBeNull();
    $this->asUser($user)->getJson('/api/applicant/portal')->assertJsonPath('data.applicationId', null);

    $this->approved('second@example.com');
    $number = $this->number($user);
    $this->asUser($this->officer())->postJson("/api/admin/applicants/{$number}/documents/verify-pending")->assertOk();
    $this->asUser($this->officer())->postJson("/api/admin/applicants/{$number}/approve", ['sessionId' => 'S'.$this->sharedSession->id])->assertOk();

    $year = now()->year;
    expect($this->app_($user)->applicant_no)->toBe("{$year}-00002");
    $this->asUser($user)->getJson('/api/applicant/portal')->assertJsonPath('data.applicationId', "{$year}-00002");
    $this->asUser($this->officer())->getJson('/api/admin/applicants/search?q='.$year.'-00002')->assertOk()->assertJsonPath('data.0.id', $number);
});

// 2.1, 2.6–2.11
test('each task reaches only its own work, and the Admission Head never takes attendance', function () {
    $user = $this->approved();
    $number = $this->number($user);
    [$personnel, $proctor, $office] = [tasked('personnel'), tasked('proctor'), tasked('exam_office')];

    // Attendance: proctor only
    $this->asUser($this->admissionHead())->patchJson("/api/admin/applicants/{$number}/exam", ['attendance' => 'absent'])->assertForbidden();
    $this->asUser($this->admissionHead())->postJson('/api/admin/exam/check-in', ['token' => 'x'])->assertForbidden();
    $this->asUser($personnel)->patchJson("/api/admin/applicants/{$number}/exam", ['attendance' => 'absent'])->assertForbidden();
    $this->asUser($office)->patchJson("/api/admin/applicants/{$number}/exam", ['attendance' => 'absent'])->assertForbidden();
    $this->asUser($proctor)->patchJson("/api/admin/applicants/{$number}/exam", ['permitChecked' => true, 'formChecked' => true, 'attendance' => 'present'])->assertOk();

    // Scores and pass decision: exam office only
    $this->asUser($proctor)->postJson("/api/admin/applicants/{$number}/result", ['score' => 82, 'decision' => 'passed'])->assertForbidden();
    $this->asUser($personnel)->postJson("/api/admin/applicants/{$number}/result", ['score' => 82, 'decision' => 'passed'])->assertForbidden();
    $this->asUser($office)->postJson("/api/admin/applicants/{$number}/result", ['score' => 82, 'decision' => 'passed'])->assertOk();

    // Documents, approval, slip and permit: admission personnel only
    $this->asUser($proctor)->getJson("/api/admin/applicants/{$number}/exam-permit")->assertForbidden();
    $this->asUser($office)->postJson("/api/admin/applicants/{$number}/documents/verify-pending")->assertForbidden();
    $this->asUser($personnel)->get("/api/admin/applicants/{$number}/exam-permit")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->asUser($personnel)->get("/api/admin/applicants/{$number}/slip")->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // Setup belongs to no task; the head still has it
    $session = ['date' => '2026-12-19', 'batch' => 'A', 'venue' => 'Room 9', 'capacity' => 20];
    $this->asUser($personnel)->postJson('/api/admin/exam-sessions', $session)->assertForbidden();
    $this->asUser($this->admissionHead())->postJson('/api/admin/exam-sessions', $session)->assertCreated();
});

// 2.7
test('the permit is issued only after approval', function () {
    $number = $this->number($this->submitted());
    $this->asUser(tasked('personnel'))->getJson("/api/admin/applicants/{$number}/exam-permit")->assertUnprocessable();
});

// 2.9
test('a proctor files a report about an applicant and the office reads it', function () {
    $number = $this->number($this->approved());
    $proctor = tasked('proctor');

    $this->asUser($proctor)->postJson("/api/admin/applicants/{$number}/reports", ['category' => 'incident', 'body' => 'Used a phone during Part II.'])
        ->assertCreated()->assertJsonPath('data.category', 'incident');
    $this->asUser($proctor)->postJson("/api/admin/applicants/{$number}/reports", ['category' => 'gossip', 'body' => 'x'])->assertUnprocessable();
    $this->asUser(tasked('exam_office'))->postJson("/api/admin/applicants/{$number}/reports", ['category' => 'other', 'body' => 'x'])->assertForbidden();
    $this->asUser($this->makeUser(Role::Proctor))->postJson("/api/admin/applicants/{$number}/reports", ['category' => 'other', 'body' => 'Late by 20 minutes.'])->assertCreated();

    $this->asUser($this->admissionHead())->getJson("/api/admin/applicants/{$number}/reports")->assertOk()->assertJsonCount(2, 'data');
    $this->assertDatabaseHas('audit_logs', ['action' => 'applicant.reported']);
});

// 2.3
test('the Admission Head assigns tasks to admission personnel', function () {
    $officer = $this->makeUser(Role::Admission);
    $this->asUser($this->admissionHead())->patchJson("/api/admin/accounts/{$officer->id}/task", ['task' => 'proctor'])->assertOk();
    expect($officer->fresh()->task)->toBe('proctor');
    $this->asUser($this->admissionHead())->postJson('/api/admin/accounts', ['name' => 'X', 'email' => 'x@plpasig.edu.ph', 'role' => 'admission'])->assertForbidden();
});

// 2.2
test('the Admission Head publishes dates and deadlines that applicants see', function () {
    $head = $this->admissionHead();
    $draft = $this->asUser($head)->postJson('/api/admin/announcements', ['title' => 'Exam dates', 'body' => 'Dec 5 and Dec 12.', 'event_date' => '2026-12-05'])
        ->assertCreated()->assertJsonPath('data.published', false)->json('data.id');
    $this->asUser($head)->postJson('/api/admin/announcements', ['title' => 'Deadline', 'body' => 'Submit by Nov 30.', 'event_date' => '2026-11-30', 'published' => true])->assertCreated();
    $this->asUser($this->officer())->postJson('/api/admin/announcements', ['title' => 'X', 'body' => 'Y'])->assertForbidden();

    $applicant = $this->makeApplicant();
    $this->asUser($applicant)->getJson('/api/applicant/announcements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Deadline');

    $this->asUser($head)->patchJson("/api/admin/announcements/{$draft}", ['published' => true])->assertOk()->assertJsonPath('data.published', true);
    $this->asUser($applicant)->getJson('/api/applicant/announcements')->assertJsonCount(2, 'data')->assertJsonPath('data.0.event_date', '2026-11-30');

    $this->asUser($head)->deleteJson("/api/admin/announcements/{$draft}")->assertNoContent();
    $this->asUser($applicant)->getJson('/api/applicant/announcements')->assertJsonCount(1, 'data');
});

// 3.1
test('a dean writes exam questions for their own college only', function () {
    $ccs = deanOf('CCS');
    $id = $this->asUser($ccs)->postJson('/api/dean/exam-questions', ['question' => '2 + 2 = ?', 'choices' => ['3', '4', '5'], 'answer' => 1])
        ->assertCreated()->assertJsonPath('data.answer', 1)->json('data.id');
    $this->asUser($ccs)->postJson('/api/dean/exam-questions', ['question' => 'Q', 'choices' => ['a', 'b'], 'answer' => 2])->assertUnprocessable();
    $this->asUser($ccs)->patchJson("/api/dean/exam-questions/{$id}", ['question' => '2 + 3 = ?', 'choices' => ['4', '5'], 'answer' => 1])->assertOk();

    $cba = deanOf('CBA');
    $this->asUser($cba)->getJson('/api/dean/exam-questions')->assertOk()->assertJsonCount(0, 'data');
    $this->asUser($cba)->deleteJson("/api/dean/exam-questions/{$id}")->assertNotFound();
    $this->asUser($this->officer())->getJson('/api/dean/exam-questions')->assertForbidden();

    $this->asUser($ccs)->getJson('/api/dean/exam-questions')->assertJsonCount(1, 'data')->assertJsonPath('data.0.question', '2 + 3 = ?');
    $this->asUser($ccs)->deleteJson("/api/dean/exam-questions/{$id}")->assertNoContent();
});
