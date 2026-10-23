<?php

use Database\Seeders\AdmissionSetupSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * H1 (PROGRESS.md), decided: keep the applicant portal's document cards.
 * Adds the two cards that had no official requirement (Grade 12 Form 138 and
 * Good Moral) and puts every admission requirement
 * in the order the portal shows its cards. Safe on a database that already
 * has data: existing rows keep their id and active/inactive setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (AdmissionSetupSeeder::ADMISSION_REQUIREMENTS as $i => [$key, $title, $when, $icon, $note]) {
            $values = ['stage' => 'admission', 'title' => $title, 'applies_when' => $when, 'icon' => $icon, 'note' => $note, 'sort_order' => $i + 1, 'updated_at' => $now];
            DB::table('requirements')->where('key', $key)->exists()
                ? DB::table('requirements')->where('key', $key)->update($values)
                : DB::table('requirements')->insert($values + ['key' => $key, 'is_uploadable' => true, 'is_active' => true, 'created_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('requirements')->whereIn('key', ['reportCard', 'goodMoral'])->delete();
    }
};
