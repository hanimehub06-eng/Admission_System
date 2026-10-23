<?php

namespace App\Console\Commands;

use App\Services\ExamService;
use App\Services\OfferService;
use Illuminate\Console\Command;

/**
 * Scheduler jobs for the admission calendar (FR-06, FR-09, FR-12):
 * forfeit offers past their acceptance deadline, remind applicants before
 * the deadline, and remind examinees the day before their exam.
 */
class ProcessDeadlines extends Command
{
    protected $signature = 'admission:deadlines
        {--only= : forfeit | offer-reminders | exam-reminders}';

    protected $description = 'Forfeit expired offers and send deadline and exam reminders';

    public function handle(OfferService $offers, ExamService $exams): int
    {
        $only = $this->option('only');

        if (! $only || $only === 'forfeit') {
            $this->line('Offers forfeited: '.$offers->forfeitExpired());
        }
        if (! $only || $only === 'offer-reminders') {
            $this->line('Acceptance reminders sent: '.$offers->remindDeadlines());
        }
        if (! $only || $only === 'exam-reminders') {
            $this->line('Exam reminders sent: '.$exams->sendReminders());
        }

        return self::SUCCESS;
    }
}
