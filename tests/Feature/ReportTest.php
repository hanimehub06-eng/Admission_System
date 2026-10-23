<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\College;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/** FR-13: reports match the database, export to Excel and PDF, and are role-scoped. */
class ReportTest extends TestCase
{
    use BuildsApplications;

    public function test_figures_and_exports(): void
    {
        $this->accepted();
        $this->released('failed', 'second@example.com');

        $rows = fn (string $report) => $this->asUser($this->officer())->getJson("/api/reports/{$report}")->assertOk()->json('data.rows');

        $this->assertContains(['BSIT', 'BS Information Technology', 2, 120, 1, 119], $rows('applicants-per-program'));
        $this->assertContains(['BSIT', 'BS Information Technology', 2, 1, 1, 50, 2], $rows('pass-rate'));
        $this->assertContains(['For medical', 1], $rows('status-breakdown'));
        $this->assertContains(['Did not pass', 1], $rows('status-breakdown'));
        $this->assertSame(2, $rows('exam-attendance')[0][5]);

        $xlsx = $this->asUser($this->officer())->get('/api/reports/status-breakdown?format=xlsx')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('Content-Type'));
        $pdf = $this->asUser($this->officer())->get('/api/reports/daily-submissions?format=pdf')->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.exported']);
    }

    public function test_role_scoping(): void
    {
        $clinic = $this->clinic();
        $dean = $this->makeUser(Role::CollegeDean);
        College::where('code', 'CON')->update(['dean_user_id' => $dean->id]);

        $this->asUser($clinic)->getJson('/api/reports')->assertOk()->assertJsonPath('data.0.key', 'medical-completion')->assertJsonCount(1, 'data');
        $this->asUser($clinic)->getJson('/api/reports/pass-rate')->assertForbidden();
        $this->asUser($clinic)->getJson('/api/reports/medical-completion')->assertOk();

        $this->asUser($dean)->getJson('/api/reports')->assertOk()->assertJsonPath('data.0.key', 'interviews')->assertJsonCount(1, 'data');
        $this->asUser($dean)->getJson('/api/reports/status-breakdown')->assertForbidden();
        $this->asUser($dean)->getJson('/api/reports/interviews')->assertOk();
    }
}
