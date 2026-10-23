<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs everything the system needs locally in one window: the web server,
 * the queue worker (emails, notifications, Enrollment hand-off) and the
 * scheduler (backups, retries). Ctrl+C stops all three.
 */
class Start extends Command
{
    protected $signature = 'app:start {--port=8000 : Port for the web server}';

    protected $description = 'Start the web server, queue worker and scheduler together';

    /** @var array<string, Process> */
    private array $processes = [];

    public function handle(): int
    {
        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        $artisan = base_path('artisan');
        $port = (int) $this->option('port');

        $this->processes = [
            'web' => new Process([$php, $artisan, 'serve', '--port='.$port]),
            'queue' => new Process([$php, $artisan, 'queue:work', '--tries=3', '--sleep=1']),
            'schedule' => new Process([$php, $artisan, 'schedule:work']),
        ];
        $colors = ['web' => 'green', 'queue' => 'cyan', 'schedule' => 'magenta'];

        $this->components->info("Starting… open http://127.0.0.1:{$port}  (admission office: /admin). Press Ctrl+C to stop.");
        foreach ($this->processes as $p) {
            $p->setTimeout(null)->start();
        }
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, fn () => $this->stopAll());
        }

        while (true) {
            foreach ($this->processes as $name => $p) {
                foreach (preg_split('/\R/', trim($p->getIncrementalOutput().$p->getIncrementalErrorOutput())) as $line) {
                    if ($line !== '') {
                        $this->line("<fg={$colors[$name]}>[{$name}]</> {$line}");
                    }
                }
                if (! $p->isRunning()) {
                    $this->components->error("The {$name} process stopped (exit code {$p->getExitCode()}). Stopping the others.");
                    $this->stopAll();

                    return self::FAILURE;
                }
            }
            usleep(200_000);
        }
    }

    private function stopAll(): void
    {
        foreach ($this->processes as $p) {
            if ($p->isRunning()) {
                $p->stop(3);
            }
        }
    }
}
