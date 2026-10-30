<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admission Head: admission dates and deadlines shown to applicants once published
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->text('body');
            $table->date('event_date')->nullable();     // the date or deadline announced
            $table->timestamp('published_at')->nullable()->index();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        // Exam proctor: a report about an applicant (e.g. an incident on exam day)
        Schema::create('applicant_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reported_by')->constrained('users');
            $table->string('category', 20);
            $table->text('body');
            $table->timestamps();
        });

        // College Dean: multiple-choice entrance exam questions for the dean's college
        Schema::create('exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->text('question');
            $table->json('choices');
            $table->unsignedTinyInteger('answer');      // index into choices
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_questions');
        Schema::dropIfExists('applicant_reports');
        Schema::dropIfExists('announcements');
    }
};
