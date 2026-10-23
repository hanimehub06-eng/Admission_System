<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-off of admitted applicants to the Enrollment system (architecture:
 * "Receives Admitted list · Returns student number"). One row per
 * application; the idempotency key stays the same across retries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_handoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained();
            $table->string('status', 20)->default('pending')->index(); // pending | sent | acknowledged | failed
            $table->uuid('idempotency_key')->unique();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->string('student_number', 30)->nullable()->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_handoffs');
    }
};
