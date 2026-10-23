<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Applicants, applications, program choices and documents (FR-01…FR-05).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('lrn', 12)->nullable()->unique();
            $table->string('last_name', 100);
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('sex', 20)->nullable();
            $table->date('birthday')->nullable();
            $table->string('civil_status', 20)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('zip', 10)->nullable();
            // Residency fields: the applicant form has none yet (decision T7)
            $table->boolean('is_resident')->nullable();
            $table->unsignedTinyInteger('district')->nullable();
            $table->string('barangay', 60)->nullable();
            $table->string('guardian_name', 150)->nullable();
            $table->string('guardian_phone', 30)->nullable();
            $table->boolean('has_guardian')->default(false);
            $table->string('last_school')->nullable();
            $table->string('school_type', 10)->nullable();  // Public | Private
            $table->string('strand', 40)->nullable();
            $table->string('grad_year', 30)->nullable();
            $table->string('applicant_type', 10)->nullable(); // grade12 | graduate
            $table->decimal('gwa', 5, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();        // AD-2026-08421
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admission_period_id')->constrained();
            $table->string('status', 20)->default('draft')->index();
            $table->string('campus', 60)->nullable();
            $table->json('form_progress')->nullable();     // { step, completed: [1,2,3] }
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('offer_accepted_at')->nullable();
            $table->timestamp('medical_submitted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['applicant_id', 'admission_period_id']);
        });

        Schema::create('application_program_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained();
            $table->unsignedTinyInteger('rank');            // 1–3
            $table->timestamps();

            $table->unique(['application_id', 'rank']);
            $table->unique(['application_id', 'program_id']);
            $table->index(['program_id', 'rank']);
        });

        Schema::create('application_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requirement_id')->constrained();
            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('is_current')->default(true);
            $table->string('status', 20)->default('pending')->index(); // pending | verified | resubmit
            $table->string('storage_driver', 20);           // local | dms
            $table->string('storage_ref');                  // local path or DMS document ID
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->string('checksum', 64)->nullable();
            $table->string('remark', 500)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // Explicit name: the generated one is longer than MySQL's 64-character limit
            $table->index(['application_id', 'requirement_id', 'is_current'], 'app_docs_current_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_documents');
        Schema::dropIfExists('application_program_choices');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('applicants');
    }
};
