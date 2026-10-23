<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pen-and-paper entrance exam: sessions, registrations (permit + attendance)
 * and results (FR-06, FR-08). Scores are internal only (NFR-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_period_id')->constrained();
            $table->date('date');
            $table->char('batch', 1);                       // A (morning) | B (afternoon)
            $table->string('venue');
            $table->unsignedSmallInteger('capacity');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['date', 'batch', 'venue']);
        });

        Schema::create('exam_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('exam_session_id')->constrained();
            $table->string('permit_no', 20)->unique();
            $table->boolean('permit_checked')->default(false);
            $table->boolean('form_checked')->default(false);
            $table->string('attendance', 10)->nullable();   // present | absent
            $table->foreignId('attendance_by')->nullable()->constrained('users');
            $table->timestamp('attendance_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index('exam_session_id');
        });

        Schema::create('exam_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score');           // internal, never sent to applicants
            $table->string('decision', 10);                 // passed | failed
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamp('recorded_at');
            $table->boolean('is_released')->default(false)->index();
            $table->foreignId('released_by')->nullable()->constrained('users');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_results');
        Schema::dropIfExists('exam_registrations');
        Schema::dropIfExists('exam_sessions');
    }
};
