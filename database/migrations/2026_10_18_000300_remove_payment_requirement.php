<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PLP is a public university: there is no application or reservation fee.
 * Passers just accept the offer. Retires the GCash / Dragonpay receipt
 * requirement wherever an earlier build created it. The row is kept (inactive,
 * not uploadable, outside every stage) because uploaded files reference it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('requirements')->where('key', 'payment')->value('id');
        if (! $id) {
            return;
        }
        DB::table('application_documents')->where('requirement_id', $id)->where('is_current', true)->update(['is_current' => false]);
        DB::table('requirements')->where('id', $id)->update([
            'stage' => 'retired', 'is_active' => false, 'is_uploadable' => false, 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Nothing to restore: the fee no longer exists.
    }
};
