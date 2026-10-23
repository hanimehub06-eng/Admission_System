<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\DmsOutboxEvent;
use App\Models\Program;
use App\Models\Requirement;
use App\Services\AuditLogger;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/** Phase 1: data layer. */
class SchemaTest extends TestCase
{
    public function test_full_seed_runs_and_loads_setup_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(11, Program::count());
        $this->assertSame(10, Requirement::admission()->count());   // 9 portal-mapped uploads + the envelope
        $this->assertFalse(Requirement::where('key', 'payment')->where('is_active', true)->exists());   // no fee: public university
        $this->assertSame(3, Requirement::medical()->count());
    }

    /** The demo data walks applicants through the real services, interviews and offers included. */
    public function test_demo_seed_runs_the_whole_flow(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);

        $statuses = Application::pluck('status')->map->value->unique();
        $this->assertTrue($statuses->contains('submitted'));
        $this->assertTrue($statuses->intersect(['offered', 'for_medical', 'admitted', 'for_interview'])->isNotEmpty());
        $this->assertSame(0, DmsOutboxEvent::where('status', '!=', DmsOutboxEvent::SENT)->count());
    }

    /** NFR-03: the audit log can't be edited through the model. */
    public function test_audit_log_model_refuses_update_and_delete(): void
    {
        $log = app(AuditLogger::class)->record('test.action', 'Did something');

        $this->expectException(LogicException::class);
        $log->update(['description' => 'Rewritten']);
    }

    /** NFR-03: the database trigger blocks raw edits too. */
    public function test_audit_log_trigger_blocks_raw_update(): void
    {
        app(AuditLogger::class)->record('test.action', 'Did something');

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->update(['description' => 'Rewritten']);
    }

    public function test_audit_log_trigger_blocks_raw_delete(): void
    {
        app(AuditLogger::class)->record('test.action', 'Did something');

        try {
            DB::table('audit_logs')->delete();
            $this->fail('Delete should have been rejected.');
        } catch (QueryException) {
            $this->assertSame(1, AuditLog::count());
        }
    }
}
