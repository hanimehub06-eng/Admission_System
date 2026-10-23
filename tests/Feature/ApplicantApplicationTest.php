<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Models\ApplicantChange;
use App\Models\Application;
use App\Models\Program;
use App\Services\ApplicationService;
use Tests\TestCase;

/** Phase 3: applicant registration and application (FR-01, FR-02, FR-05, FR-10). */
class ApplicantApplicationTest extends TestCase
{
    private function attachRequiredDocuments(Application $application): void
    {
        foreach (app(ApplicationService::class)->requiredDocuments($application) as $requirement) {
            $application->allDocuments()->create([
                'requirement_id' => $requirement->id, 'status' => 'pending', 'storage_driver' => 'local',
                'storage_ref' => 'documents/test.pdf', 'original_name' => $requirement->key.'.pdf', 'mime' => 'application/pdf', 'size' => 1000,
            ]);
        }
    }

    public function test_portal_returns_draft_state_in_the_ui_shape(): void
    {
        $user = $this->makeApplicant();

        $this->asUser($user)->getJson('/api/applicant/portal')
            ->assertOk()
            ->assertJsonPath('data.phase', 'draft')
            ->assertJsonPath('data.step', 1)
            ->assertJsonPath('data.completed', [])
            ->assertJsonPath('data.data.firstName', 'Jordan')
            ->assertJsonPath('data.data.email', 'jordan@example.com')
            ->assertJsonPath('data.accepted', false)
            ->assertJsonPath('data.medical.status', 'none')
            ->assertJsonPath('data.exam', null)
            ->assertJsonPath('data.result', null)
            ->assertJsonCount(11, 'data.programs')
            ->assertJsonPath('data.keyDates.0.date', '2026-11-28');
    }

    /** FR-01: autosave keeps good values and skips half-typed ones */
    public function test_autosave_skips_invalid_values_and_keeps_the_rest(): void
    {
        $user = $this->makeApplicant();

        $res = $this->asUser($user)->putJson('/api/applicant/application', [
            'middleName' => 'Reyes', 'lrn' => '12345', 'city' => 'Pasig City', 'step' => 2,
        ])->assertOk();

        $res->assertJsonPath('data.data.middleName', 'Reyes')
            ->assertJsonPath('data.data.lrn', null)
            ->assertJsonPath('data.step', 2)
            ->assertJsonStructure(['skipped' => ['lrn']]);
        // Decision T7: residency derived from the city
        $this->assertTrue($user->applicant->fresh()->is_resident);
    }

    public function test_marking_a_section_complete_validates_it(): void
    {
        $user = $this->makeApplicant();

        $this->asUser($user)->putJson('/api/applicant/application', ['completed' => [1]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['birthDate', 'address', 'guardianName']);

        $this->asUser($user)->putJson('/api/applicant/application', $this->completeForm(['completed' => [1, 2, 3]]))
            ->assertOk()
            ->assertJsonPath('data.completed', [1, 2, 3]);
    }

    /** FR-02: choices are open programs, no duplicates */
    public function test_program_choices(): void
    {
        $user = $this->makeApplicant();
        Program::where('code', 'BSN')->update(['is_open' => false]);

        $res = $this->asUser($user)->putJson('/api/applicant/application', [
            'program1' => 'BS Information Technology',
            'program2' => 'BS Information Technology',
            'program3' => 'BS Nursing',
        ])->assertOk();

        $res->assertJsonPath('data.data.program1', 'BS Information Technology')
            ->assertJsonPath('data.data.program2', null)
            ->assertJsonStructure(['skipped' => ['program2', 'program3']]);
        $this->assertSame(1, $this->applicationOf($user)->choices()->count());
    }

    public function test_lrn_must_be_unique(): void
    {
        $this->makeApplicant(true);
        $other = $this->makeApplicant(false, 'other@example.com');

        $this->asUser($other)->putJson('/api/applicant/application', ['lrn' => '123456789012'])
            ->assertJsonStructure(['skipped' => ['lrn']]);
    }

    /** FR-05: submit needs the form and the documents */
    /** "My profile": names while a draft, mobile number any time, changes after submission kept (FR-10) */
    public function test_profile_update(): void
    {
        $user = $this->makeApplicant(true);
        $this->asUser($user)->putJson('/api/applicant/profile', ['firstName' => 'Jordy', 'lastName' => 'Mendoza', 'phone' => '0917 000 1111'])
            ->assertOk()->assertJsonPath('data.data.firstName', 'Jordy')->assertJsonPath('data.data.phone', '0917 000 1111');
        $this->asUser($user)->putJson('/api/applicant/profile', ['phone' => '12345'])
            ->assertUnprocessable()->assertJsonPath('errors.phone.0', 'Use a PH mobile number, like +63 917 123 4567.');

        // After the application is sent: the number still changes (and is logged), the name doesn't
        $this->applicationOf($user)->forceFill(['status' => ApplicationStatus::Submitted])->save();
        $this->asUser($user)->putJson('/api/applicant/profile', ['firstName' => 'Someone', 'phone' => '+63 918 222 3333'])
            ->assertOk()->assertJsonPath('data.data.firstName', 'Jordy')->assertJsonPath('data.data.phone', '+63 918 222 3333');
        $this->assertDatabaseHas('applicant_change_history', ['field' => 'phone', 'old_value' => '0917 000 1111', 'new_value' => '+63 918 222 3333']);
        $this->assertDatabaseMissing('applicant_change_history', ['field' => 'first_name']);
    }

    public function test_submit_requires_complete_form_and_documents(): void
    {
        $user = $this->makeApplicant();
        $this->asUser($user)->postJson('/api/applicant/submit')
            ->assertUnprocessable()->assertJsonPath('message', 'Finish all three sections of My application first.');

        $user = $this->makeApplicant(true, 'filled@example.com');
        $this->asUser($user)->postJson('/api/applicant/submit')
            ->assertUnprocessable()->assertJsonFragment(['message' => 'Upload these documents first: Applicant\'s government-issued or school ID, Parent\'s government-issued ID, CTC of Grade 11 Form 138, Grade 12 Form 138 (latest grading period), Certificate of Good Moral Character, PSA Birth Certificate, 2 passport-size photos, white background with nameplate.']);
    }

    public function test_submit_locks_the_application_and_records_history(): void
    {
        $user = $this->makeApplicant(true);
        $application = $this->applicationOf($user);
        $this->attachRequiredDocuments($application);

        $this->asUser($user)->postJson('/api/applicant/submit')
            ->assertOk()
            ->assertJsonPath('data.phase', 'review')
            ->assertJsonPath('data.submittedAt', now('Asia/Manila')->toDateString());

        $application->refresh();
        $this->assertSame(ApplicationStatus::Submitted, $application->status);
        $this->assertNotNull($application->locked_at);
        $this->assertSame(2, $application->statusHistory()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'application.submitted', 'application_id' => $application->id, 'actor_name' => 'Applicant']);

        $this->asUser($user)->putJson('/api/applicant/application', ['middleName' => 'X'])->assertUnprocessable();
        $this->asUser($user)->postJson('/api/applicant/submit')->assertUnprocessable();
    }

    /** FR-10: edits after the first submission are kept in the change history */
    public function test_edits_during_resubmission_are_recorded(): void
    {
        $user = $this->makeApplicant(true);
        $application = $this->applicationOf($user);
        $this->attachRequiredDocuments($application);
        $this->asUser($user)->postJson('/api/applicant/submit')->assertOk();
        $application->refresh()->update(['status' => ApplicationStatus::Resubmit]);

        $this->asUser($user)->getJson('/api/applicant/portal')->assertJsonPath('data.phase', 'draft');
        $this->asUser($user)->putJson('/api/applicant/application', ['address' => '99 Shaw Blvd.'])->assertOk();

        $this->assertDatabaseHas('applicant_change_history', ['field' => 'address', 'old_value' => '12 Mabini St.', 'new_value' => '99 Shaw Blvd.']);
        $this->assertSame(1, ApplicantChange::count());
    }

    /** NFR-01 */
    public function test_only_applicants_use_the_portal_api(): void
    {
        $this->asUser($this->makeUser(Role::Admission))->getJson('/api/applicant/portal')->assertForbidden();
        $this->flushHeaders()->getJson('/api/applicant/portal')->assertUnauthorized();
    }
}
