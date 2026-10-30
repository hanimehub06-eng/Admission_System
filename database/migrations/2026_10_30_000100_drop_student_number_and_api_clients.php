<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * School Systems API Standard (docs/API_Guide.md):
 * - Student numbers belong to the Enrollment System, not Admission.
 * - No other system calls the OAS any more (we pull from EMS and send to
 *   the DMS and Enrollment), so the inbound API keys go.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropUnique(['student_number']);
            $table->dropColumn('student_number');
        });
        Schema::dropIfExists('api_clients');
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('student_number', 30)->nullable()->unique()->after('admitted_at');
        });
        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->string('key_hash', 64)->unique();
            $table->json('allowed_ips')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }
};
