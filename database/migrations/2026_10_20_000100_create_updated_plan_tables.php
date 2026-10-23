<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Updated development plan (solution design paper, 2026-10-20 draft):
 * colleges and the College Dean, interviews (FR-17), slot acceptance with a
 * deadline and forfeit (FR-09), Admitted (FR-10/FR-11), photocopies and
 * falsification flags (FR-03, FR-10), QR permits (FR-07), trusted devices
 * (NFR-04), API clients, and the DMS outbox (INT-01).
 *
 * The direct Enrollment hand-off is replaced by the DMS (decision D12):
 * the student number, when Enrollment sends one back, lives on the
 * application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('privacy_accepted_at')->nullable()->after('email_verified_at');
        });

        Schema::create('colleges', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->foreignId('dean_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->foreignId('college_id')->nullable()->after('id')->constrained();
            $table->boolean('requires_interview')->default(false)->after('is_open');
        });

        Schema::table('admission_periods', function (Blueprint $table) {
            $table->unsignedSmallInteger('acceptance_window_days')->default(7)->after('reservation_deadline');
        });

        Schema::table('applicants', function (Blueprint $table) {
            // Flagged for staff review, never blocked (FR-01)
            $table->foreignId('possible_duplicate_of')->nullable()->after('user_id')->constrained('applicants')->nullOnDelete();
            $table->index(['last_name', 'first_name', 'birthday']);
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->foreignId('offered_program_id')->nullable()->after('campus')->constrained('programs');
            $table->timestamp('offered_at')->nullable()->after('locked_at');
            $table->timestamp('acceptance_deadline')->nullable()->after('offered_at')->index();
            $table->timestamp('acceptance_reminded_at')->nullable()->after('acceptance_deadline');
            $table->timestamp('forfeited_at')->nullable()->after('offer_accepted_at');
            $table->timestamp('admitted_at')->nullable()->after('medical_submitted_at');
            $table->string('student_number', 30)->nullable()->unique()->after('admitted_at');
        });

        Schema::table('application_documents', function (Blueprint $table) {
            $table->string('scan_status', 10)->nullable()->after('checksum');   // clean | skipped
            $table->timestamp('photocopy_received_at')->nullable()->after('reviewed_at');
            $table->foreignId('photocopy_received_by')->nullable()->after('photocopy_received_at')->constrained('users');
            $table->boolean('falsification_flag')->default(false)->after('photocopy_received_by');
            $table->string('falsification_note', 500)->nullable()->after('falsification_flag');
            $table->foreignId('falsification_flagged_by')->nullable()->after('falsification_note')->constrained('users');
        });

        Schema::table('exam_registrations', function (Blueprint $table) {
            $table->string('qr_token', 64)->nullable()->unique()->after('permit_no');
            $table->timestamp('reminder_sent_at')->nullable()->after('approved_by');
        });

        Schema::create('interview_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_period_id')->constrained();
            $table->foreignId('program_id')->constrained();
            $table->date('date');
            $table->string('time', 40)->nullable();          // "9:00 AM – 12:00 NN"
            $table->string('venue');
            $table->unsignedSmallInteger('capacity');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['program_id', 'date']);
        });

        Schema::create('interview_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('interview_schedule_id')->constrained();
            $table->foreignId('assigned_by')->nullable()->constrained('users');
            $table->unsignedTinyInteger('grade')->nullable();    // Restricted: never sent to applicants
            $table->string('outcome', 10)->nullable();           // passed | failed
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->index('interview_schedule_id');
        });

        // Applicants skip the email code on a device they confirmed before (NFR-04)
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('label')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        // Other ERP systems calling us (DMS, Enrollment). Managed by the sysadmin.
        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();           // enrollment | dms
            $table->string('key_hash', 64)->unique();
            $table->json('allowed_ips')->nullable();        // null = any (Nginx still filters)
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        // INT-01: every record change goes to the DMS through this table, in the
        // same transaction as the change, then a queued job delivers it.
        Schema::create('dms_outbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();             // Idempotency-Key
            $table->string('type', 60)->index();
            $table->string('applicant_ref', 20)->index();   // application number
            $table->json('payload');
            $table->string('status', 10)->default('pending')->index(); // pending | sent | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        // D12: no direct OAS → Enrollment call any more. Keep any student numbers.
        if (Schema::hasTable('enrollment_handoffs')) {
            foreach (DB::table('enrollment_handoffs')->whereNotNull('student_number')->get() as $h) {
                DB::table('applications')->where('id', $h->application_id)->update(['student_number' => $h->student_number]);
            }
            Schema::drop('enrollment_handoffs');
        }
    }

    public function down(): void
    {
        Schema::create('enrollment_handoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained();
            $table->string('status', 20)->default('pending')->index();
            $table->uuid('idempotency_key')->unique();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->string('student_number', 30)->nullable()->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('dms_outbox');
        Schema::dropIfExists('api_clients');
        Schema::dropIfExists('trusted_devices');
        Schema::dropIfExists('interview_assignments');
        Schema::dropIfExists('interview_schedules');

        Schema::table('exam_registrations', function (Blueprint $table) {
            $table->dropUnique(['qr_token']);
            $table->dropColumn(['qr_token', 'reminder_sent_at']);
        });
        Schema::table('application_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('photocopy_received_by');
            $table->dropConstrainedForeignId('falsification_flagged_by');
            $table->dropColumn(['scan_status', 'photocopy_received_at', 'falsification_flag', 'falsification_note']);
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('offered_program_id');
            $table->dropIndex(['acceptance_deadline']);
            $table->dropUnique(['student_number']);
            $table->dropColumn(['offered_at', 'acceptance_deadline', 'acceptance_reminded_at', 'forfeited_at', 'admitted_at', 'student_number']);
        });
        Schema::table('applicants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('possible_duplicate_of');
            $table->dropIndex(['last_name', 'first_name', 'birthday']);
        });
        Schema::table('admission_periods', fn (Blueprint $table) => $table->dropColumn('acceptance_window_days'));
        Schema::table('programs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('college_id');
            $table->dropColumn('requires_interview');
        });
        Schema::dropIfExists('colleges');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('privacy_accepted_at'));
    }
};
