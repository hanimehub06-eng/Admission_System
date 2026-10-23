<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AdmissionPeriod;
use App\Models\Program;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/** Phase 5: programs, slots, requirements, admission period (FR-09). */
class SetupTest extends TestCase
{
    use BuildsApplications;

    public function test_officer_changes_slots_and_adds_program(): void
    {
        $this->asUser($this->officer())->patchJson('/api/admin/programs/BSIT', ['slots' => 150])
            ->assertOk()->assertJsonPath('data.slots', 150);
        $this->asUser($this->officer())->patchJson('/api/admin/programs/BSIT', ['slots' => 0])->assertUnprocessable();

        $this->asUser($this->officer())->postJson('/api/admin/programs', ['code' => 'bsce', 'name' => 'BS Civil Engineering', 'slots' => 40])
            ->assertCreated()->assertJsonPath('data.code', 'BSCE');
        $this->asUser($this->officer())->postJson('/api/admin/programs', ['code' => 'BSCE', 'name' => 'Again', 'slots' => 40])->assertUnprocessable();

        $this->assertDatabaseHas('audit_logs', ['description' => 'Changed BSIT slots from 120 to 150']);
        $this->assertDatabaseHas('audit_logs', ['description' => 'Added program BSCE · BS Civil Engineering (40 slots)']);
        // Applicants see the new program
        $this->asUser($this->makeApplicant())->getJson('/api/applicant/portal')->assertJsonFragment(['BS Civil Engineering']);
    }

    /** FR-09: slots are taken on acceptance, and can't be set below what's taken */
    public function test_accepted_offers_fill_slots(): void
    {
        $this->accepted();
        $this->assertSame(1, Program::where('code', 'BSIT')->first()->filledSlots());

        $this->asUser($this->officer())->patchJson('/api/admin/programs/BSIT', ['slots' => 1])->assertOk();

        // A second offer for the full program can't be accepted
        $second = $this->released('passed', 'second@example.com');
        $this->asUser($second)->postJson('/api/applicant/offer/accept')
            ->assertStatus(422)->assertJsonPath('message', 'All BSIT slots have been taken. Contact the admission office.');
    }

    public function test_requirements_and_deadline(): void
    {
        $this->asUser($this->officer())->patchJson('/api/admin/requirements/parentId', ['active' => false])->assertOk();
        $this->asUser($this->officer())->getJson('/api/admin/state')->assertJsonPath('data.settings.inactiveDocs', ['parentId']);

        // A switched-off requirement is no longer needed to submit
        $user = $this->makeApplicant(true);
        $this->asUser($user)->postJson('/api/applicant/submit')->assertJsonMissing(['Parent\'s government-issued ID']);

        $this->asUser($this->officer())->patchJson('/api/admin/admission-period', ['deadline' => '2026-12-04'])
            ->assertOk()->assertJsonPath('data.deadline', '2026-12-04');
        $this->assertSame('2026-12-04', AdmissionPeriod::current()->application_deadline->toDateString());
        $this->assertDatabaseHas('audit_logs', ['description' => 'Stopped requiring "Parent\'s government-issued ID"']);
        $this->assertDatabaseHas('audit_logs', ['description' => 'Changed the application deadline to December 4, 2026']);
    }

    /** NFR-01: the clinic can't change admission setup */
    public function test_clinic_cannot_change_setup(): void
    {
        $this->asUser($this->makeUser(Role::Clinic))->patchJson('/api/admin/programs/BSIT', ['slots' => 10])->assertForbidden();
    }
}
