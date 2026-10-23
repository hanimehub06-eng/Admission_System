<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Status history, applicant change history and the audit log (FR-05, FR-10,
 * NFR-03). All three are append-only: database triggers reject UPDATE and
 * DELETE, so even a bug or a manual query can't rewrite them.
 */
return new class extends Migration
{
    private const APPEND_ONLY = ['application_status_history', 'applicant_change_history', 'audit_logs'];

    public function up(): void
    {
        Schema::create('application_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('changed_by')->nullable()->constrained('users');
            $table->string('remark', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['application_id', 'created_at']);
        });

        Schema::create('applicant_change_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained();
            $table->string('field', 50);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['applicant_id', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('actor_name', 150);              // "Maria Santos", "Applicant", "System"
            $table->string('action', 60)->index();          // document.verified, result.released…
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('application_id')->nullable()->constrained();
            $table->string('description', 500);             // the sentence the audit screen shows
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        foreach (self::APPEND_ONLY as $table) {
            $this->protect($table);
        }
    }

    public function down(): void
    {
        foreach (self::APPEND_ONLY as $table) {
            $this->unprotect($table);
        }
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('applicant_change_history');
        Schema::dropIfExists('application_status_history');
    }

    private function protect(string $table): void
    {
        foreach (['UPDATE', 'DELETE'] as $op) {
            $name = "{$table}_no_".strtolower($op);
            $message = "{$table} is append-only";

            match (DB::getDriverName()) {
                'mysql', 'mariadb' => $this->mysqlTrigger(
                    "CREATE TRIGGER {$name} BEFORE {$op} ON {$table} FOR EACH ROW
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'"
                ),
                'sqlite' => DB::unprepared(
                    "CREATE TRIGGER {$name} BEFORE {$op} ON {$table}
                     BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                ),
                'pgsql' => null, // not a target database
            };
        }
    }

    /**
     * With binary logging on, MySQL lets a normal user create triggers only
     * when log_bin_trust_function_creators is on. The audit protection is
     * required (NFR-03), so stop with instructions instead of skipping it.
     */
    private function mysqlTrigger(string $sql): void
    {
        try {
            DB::unprepared($sql);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1419) {
                throw $e;
            }
            throw new RuntimeException(
                "MySQL won't let this user create the audit-log triggers.\n".
                "As the MySQL root user, run once:  SET PERSIST log_bin_trust_function_creators = 1;\n".
                "(Amazon RDS: set log_bin_trust_function_creators = 1 in the DB parameter group.)\n".
                'Then run the migration again.', 0, $e);
        }
    }

    private function unprotect(string $table): void
    {
        foreach (['update', 'delete'] as $op) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_{$op}");
        }
    }
};
