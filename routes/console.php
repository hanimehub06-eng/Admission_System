<?php

use Illuminate\Support\Facades\Schedule;

/*
| Scheduler (paper §7.2: "Queue worker + scheduler"). On the server, cron
| runs `php artisan schedule:run` every minute next to the queue worker
| (Supervisor), which sends the queued emails and DMS events. Locally,
| `php artisan app:start` runs both.
*/

// FR-09: forfeit offers past their deadline (hourly), remind before it and before the exam (daily, 8 AM Manila)
Schedule::command('admission:deadlines --only=forfeit')->hourly()->withoutOverlapping();
Schedule::command('admission:deadlines --only=offer-reminders')->dailyAt('08:00')->timezone('Asia/Manila')->withoutOverlapping();
Schedule::command('admission:deadlines --only=exam-reminders')->dailyAt('08:00')->timezone('Asia/Manila')->withoutOverlapping();

// INT-01: anything the DMS didn't take yet goes again
Schedule::command('dms:outbox --retry')->hourly()->withoutOverlapping();

// NFR-06: nightly encrypted backup
Schedule::command('app:backup')->dailyAt('02:00')->timezone('Asia/Manila')->withoutOverlapping();

Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('queue:prune-batches --hours=168')->daily();
