<?php

namespace Tests\Feature;

use App\Enums\Role;
use Tests\TestCase;

/** The Super Admin assigns each Admission Personnel account a task (Accounts); it is audited and sent to the panel */
class TaskAssignmentTest extends TestCase
{
    public function test_super_admin_assigns_tasks_to_admission_personnel(): void
    {
        $super = $this->makeUser(Role::SuperAdmin);
        $officer = $this->makeUser(Role::Admission, ['name' => 'Rosa Reyes']);

        $this->asUser($super)->patchJson("/api/admin/accounts/{$officer->id}/task", ['task' => 'exam_office'])->assertOk()->assertJsonPath('data.task', 'exam_office');
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.task', 'description' => "Assigned Rosa Reyes ({$officer->email}) the task: Exam Office"]);

        // The panel gets it, and Accounts lists it
        $this->assertSame('exam_office', $this->asUser($officer)->getJson('/api/admin/state')->assertOk()->json('data.staff.task'));
        $this->assertSame('exam_office', collect($this->asUser($super)->getJson('/api/admin/accounts')->json('data'))->firstWhere('id', $officer->id)['task']);

        // Removing it gives back every screen; unknown tasks, other roles and other callers are refused
        $this->asUser($super)->patchJson("/api/admin/accounts/{$officer->id}/task", ['task' => null])->assertOk();
        $this->assertNull($officer->fresh()->task);
        $this->asUser($super)->patchJson("/api/admin/accounts/{$officer->id}/task", ['task' => 'all'])->assertUnprocessable();
        $this->asUser($super)->patchJson('/api/admin/accounts/'.$this->makeUser(Role::Clinic)->id.'/task', ['task' => 'proctor'])->assertUnprocessable();
        $this->asUser($this->makeUser(Role::Clinic))->patchJson("/api/admin/accounts/{$officer->id}/task", ['task' => 'proctor'])->assertForbidden();
        $this->asUser($officer)->patchJson("/api/admin/accounts/{$officer->id}/task", ['task' => 'proctor'])->assertForbidden();
    }
}
