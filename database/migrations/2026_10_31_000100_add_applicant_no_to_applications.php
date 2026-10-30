<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Applicant ID (2026-00001) is issued only when admission approves the
 * application (ExamService::approve), not at sign-up. `number` stays the
 * internal application reference (route key, DMS id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('applicant_no', 20)->nullable()->unique()->after('number');
        });

        // Applications already approved for the exam get their ID in approval order
        $seq = [];
        DB::table('applications')->join('exam_registrations as r', 'r.application_id', '=', 'applications.id')
            ->orderBy('r.created_at')->orderBy('applications.id')
            ->get(['applications.id', 'r.created_at'])
            ->each(function ($row) use (&$seq) {
                $year = substr((string) $row->created_at, 0, 4);
                $seq[$year] = ($seq[$year] ?? 0) + 1;
                DB::table('applications')->where('id', $row->id)->update(['applicant_no' => sprintf('%s-%05d', $year, $seq[$year])]);
            });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropUnique(['applicant_no']);
            $table->dropColumn('applicant_no');
        });
    }
};
