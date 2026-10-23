<?php

namespace App\Console\Commands;

use App\Models\Program;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * One-time setup, safe to run again: keys, database check, tables, data.
 * Never overwrites an existing key or reseeds a database that has data.
 */
class Install extends Command
{
    protected $signature = 'app:install';

    protected $description = 'Set up the admission system: keys, database tables and starting data';

    public function handle(): int
    {
        $this->components->info('PLP Online Admission · setup');

        $this->components->task('Application key', fn () => $this->ensureKey('APP_KEY', 'base64:'.base64_encode(random_bytes(32))));
        $this->components->task('JWT secret', fn () => $this->ensureKey('JWT_SECRET', Str::random(64)));

        if (! $this->databaseReachable()) {
            return self::FAILURE;
        }
        if (! $this->triggersAllowed()) {
            return self::FAILURE;
        }

        $this->components->task('Database tables', fn () => Artisan::call('migrate', ['--force' => true]) === 0);

        if (Program::exists()) {
            $this->components->twoColumnDetail('Starting data', '<fg=yellow>already there, not reseeded</>');
            // Roles and colleges added by later versions (both only fill in what's missing)
            $this->components->task('New roles and colleges', fn () => Artisan::call('db:seed', ['--class' => RoleSeeder::class, '--force' => true]) === 0
                && Artisan::call('db:seed', ['--class' => CollegeSeeder::class, '--force' => true]) === 0);
        } else {
            $this->components->info('Loading starting data'.(app()->environment('local') ? ' and demo applicants (about a minute)' : '').'…');
            Artisan::call('db:seed', ['--force' => true], $this->output);
        }

        $this->newLine();
        $this->components->info('Done. Start the system with:  php artisan app:start');
        $this->line('  Applicant portal   '.url('/').'  (create an account)');
        $this->line('  Admission office   '.url('/admin'));
        if (app()->environment('local')) {
            $this->line('  Demo logins        admissions@plpasig.edu.ph · clinic@plpasig.edu.ph · password123');
        }

        return self::SUCCESS;
    }

    /** Write a value to .env only when it's empty. */
    private function ensureKey(string $name, string $value): bool
    {
        $path = base_path('.env');
        $env = file_get_contents($path);
        if (preg_match("/^{$name}=(.+)$/m", $env, $m) && trim($m[1], " \"'\r") !== '') {
            return true;
        }
        $env = preg_match("/^{$name}=.*$/m", $env)
            ? preg_replace("/^{$name}=.*$/m", "{$name}={$value}", $env)
            : rtrim($env).PHP_EOL."{$name}={$value}".PHP_EOL;
        file_put_contents($path, $env);
        putenv("{$name}={$value}");
        config([$name === 'APP_KEY' ? 'app.key' : 'jwt.secret' => $value]);

        return true;
    }

    private function databaseReachable(): bool
    {
        $c = config('database.connections.'.config('database.default'));
        try {
            if ($c['driver'] === 'sqlite' && $c['database'] !== ':memory:' && ! file_exists($c['database'])) {
                touch($c['database']);
            }
            DB::connection()->getPdo();
            $this->components->twoColumnDetail('Database', '<fg=green>'.$c['driver'].' · '.($c['database'] ?? '').'</>');

            return true;
        } catch (Throwable $e) {
            $this->components->error("Can't connect to the database: ".$e->getMessage());
            $this->line('  Check DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD in .env.');
            $this->line('  For MySQL, the database must already exist (CREATE DATABASE admission_system).');

            return false;
        }
    }

    /** MySQL with binary logging needs this setting for the audit-log triggers (NFR-03). */
    private function triggersAllowed(): bool
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return true;
        }
        $row = DB::selectOne('SELECT @@log_bin AS log_bin, @@log_bin_trust_function_creators AS trusted');
        if (! $row->log_bin || $row->trusted) {
            return true;
        }
        $already = DB::getSchemaBuilder()->hasTable('audit_logs');
        if ($already) {
            return true; // tables and triggers were created earlier
        }
        $this->components->error('MySQL needs one setting before the audit-log tables can be created.');
        $this->line('  As the MySQL root user, run once:  SET PERSIST log_bin_trust_function_creators = 1;');
        $this->line('  Then run  php artisan app:install  again.');

        return false;
    }
}
