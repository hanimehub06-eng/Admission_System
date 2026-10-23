<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Least privilege for MySQL (NFR-03, plan §5 "DB user for the app has
 * INSERT/SELECT only on audit_logs"). Prints the SQL for a MySQL admin to
 * run; it changes nothing by itself.
 *
 *  - runtime user (what .env uses in production): read/write on normal
 *    tables, but only SELECT + INSERT on the append-only tables
 *  - migration user: schema changes, used only when deploying
 */
class DatabasePrivileges extends Command
{
    protected $signature = 'app:db-privileges
        {--app-user=admission_app : MySQL user the running app connects as}
        {--migrate-user=admission_migrate : MySQL user used only for php artisan migrate}
        {--host=localhost : Host part of both MySQL accounts}';

    protected $description = 'Print the MySQL GRANT statements for least-privilege database users';

    /** Rows here are never changed or deleted by the app. */
    public const APPEND_ONLY = ['audit_logs', 'application_status_history', 'applicant_change_history'];

    public function handle(): int
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->error('This command is for MySQL. The current connection is '.DB::getDriverName().'.');

            return self::FAILURE;
        }

        $db = DB::getDatabaseName();
        $host = $this->option('host');
        $app = "'{$this->option('app-user')}'@'{$host}'";
        $migrate = "'{$this->option('migrate-user')}'@'{$host}'";
        $tables = collect(Schema::getTableListing(schema: $db, schemaQualified: false))->sort()->values();

        $lines = [
            "-- Least-privilege users for `{$db}`. Run as a MySQL admin (root).",
            '-- Replace the passwords. Run again after migrations add tables.',
            '',
            "CREATE USER IF NOT EXISTS {$migrate} IDENTIFIED BY 'change-me-migrate';",
            "GRANT ALL PRIVILEGES ON `{$db}`.* TO {$migrate};",
            '',
            "CREATE USER IF NOT EXISTS {$app} IDENTIFIED BY 'change-me-app';",
            "REVOKE ALL PRIVILEGES, GRANT OPTION FROM {$app};",
        ];
        foreach ($tables as $table) {
            $rights = in_array($table, self::APPEND_ONLY, true) ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE';
            $lines[] = "GRANT {$rights} ON `{$db}`.`{$table}` TO {$app};";
        }
        $lines[] = 'FLUSH PRIVILEGES;';

        $this->line(implode(PHP_EOL, $lines));
        $this->newLine();
        $this->comment('Production .env: DB_USERNAME = the app user. Deploy with DB_USERNAME = the migrate user for `php artisan migrate --force`.');

        return self::SUCCESS;
    }
}
