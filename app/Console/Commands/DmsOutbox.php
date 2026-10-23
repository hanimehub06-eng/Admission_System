<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\DmsOutboxEvent;
use App\Services\Dms\DmsSync;
use Illuminate\Console\Command;

/**
 * DMS sync status (INT-01): counts, the failed (dead-letter) events, and a
 * retry. --resync puts an applicant's full record on the outbox again
 * (reconciliation after a DMS restore or a contract change).
 */
class DmsOutbox extends Command
{
    protected $signature = 'dms:outbox
        {--retry : Send pending and failed events again}
        {--resync= : Application number (AD-2026-00001) whose record to send again}';

    protected $description = 'Show the DMS outbox, retry failed events, or resync an applicant';

    public function handle(DmsSync $dms): int
    {
        if ($number = $this->option('resync')) {
            $application = Application::where('number', $number)->first();
            if (! $application) {
                $this->error("No application {$number}.");

                return self::FAILURE;
            }
            $dms->record('applicant.upserted', $application);
            $dms->record('status.changed', $application, ['from' => null, 'to' => $application->status->value, 'remark' => 'Resync']);
            $this->info("Queued {$number} for the DMS.");

            return self::SUCCESS;
        }

        if ($this->option('retry')) {
            $this->info('Re-queued '.$dms->retryOutstanding().' event(s).');

            return self::SUCCESS;
        }

        $counts = DmsOutboxEvent::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $this->table(['Status', 'Events'], collect(['pending', 'sent', 'failed'])->map(fn ($s) => [$s, $counts[$s] ?? 0]));

        $failed = DmsOutboxEvent::where('status', DmsOutboxEvent::FAILED)->latest('id')->limit(20)->get();
        if ($failed->isNotEmpty()) {
            $this->warn('Latest failed events (run with --retry to send them again):');
            $this->table(['Event', 'Type', 'Applicant', 'Attempts', 'Error'], $failed->map(fn ($e) => [
                $e->event_id, $e->type, $e->applicant_ref, $e->attempts, mb_strimwidth((string) $e->last_error, 0, 60, '…'),
            ]));
        }

        return self::SUCCESS;
    }
}
