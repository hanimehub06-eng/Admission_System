<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admission periods, programs and requirements (FR-09).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_periods', function (Blueprint $table) {
            $table->id();
            $table->string('label');                       // "AY 2027–2028 · 1st semester"
            $table->string('academic_year', 20);           // "2027–2028"
            $table->date('opens_on');
            $table->date('application_deadline');
            $table->date('exam_starts_on')->nullable();
            $table->date('exam_ends_on')->nullable();
            $table->date('results_release_on')->nullable();
            $table->date('reservation_deadline')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->unsignedInteger('slots');
            $table->boolean('is_open')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Generic on purpose: admission documents and medical requirements share it.
        Schema::create('requirements', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();           // matches the UI keys (psa, parentId, medCert…)
            $table->string('stage', 20)->index();          // admission | medical
            $table->string('title');
            $table->string('note')->nullable();
            $table->string('applies_when', 20)->default('all'); // all | guardian | graduate | grade12
            $table->string('icon', 30)->nullable();
            $table->boolean('is_uploadable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requirements');
        Schema::dropIfExists('programs');
        Schema::dropIfExists('admission_periods');
    }
};
