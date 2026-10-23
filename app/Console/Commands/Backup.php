<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Sysadmin backup (NFR-06, INT-05; staff can't run backups). One zip with
 * the database dump and the document files kept on this server. Runs
 * nightly from the scheduler (RPO 24 h).
 *
 * - BACKUP_PASSWORD set: every entry is AES-256 encrypted (opens with
 *   7-Zip or `unzip` builds with AES support, using that password).
 * - BACKUP_REMOTE_DISK set: the zip is also copied off the server (s3 on
 *   AWS, or a Google Drive disk as the paper suggests).
 *
 * On Amazon RDS, keep RDS automated backups on as well; this command is the
 * application-level copy that can be restored anywhere. Restore steps:
 * docs/DEPLOYMENT_AWS.md.
 */
class Backup extends Command
{
    protected $signature = 'app:backup
        {--keep= : How many backups to keep (default from config)}
        {--connection= : Database connection to back up (default: the one the app uses)}';

    protected $description = 'Back up the database and the uploaded documents into storage/app/backups';

    public function handle(AuditLogger $audit): int
    {
        $dir = config('admission.backups.path');
        File::ensureDirectoryExists($dir);
        $stamp = now()->format('Y-m-d_His');
        $zipPath = "{$dir}/backup_{$stamp}.zip";
        $tmp = storage_path("app/backup_tmp_{$stamp}");
        File::ensureDirectoryExists($tmp);

        try {
            $dump = $this->dumpDatabase($tmp);
            $files = $this->zip($zipPath, $dump);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($tmp);
        }

        $removed = $this->prune($dir, (int) ($this->option('keep') ?: config('admission.backups.keep')));
        $size = round(filesize($zipPath) / 1048576, 1);
        $encrypted = (bool) config('admission.backups.password');

        $remote = null;
        if ($disk = config('admission.backups.remote_disk')) {
            try {
                $remote = $this->copyOffServer($zipPath, $disk);
            } catch (\Throwable $e) {
                $audit->record('system.backup_failed', "Backup written locally but not copied to {$disk}: ".mb_substr($e->getMessage(), 0, 200));
                $this->error("Backup written to {$zipPath}, but the copy to the \"{$disk}\" disk failed: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        $audit->record('system.backup', "Created a backup ({$size} MB, {$files} documents".($encrypted ? ', encrypted' : '').($remote ? ", copied to {$disk}" : '').')', meta: ['file' => basename($zipPath)]);
        $this->info("Backup written: {$zipPath} ({$size} MB, {$files} document files".($encrypted ? ', encrypted' : ', NOT encrypted: set BACKUP_PASSWORD').')'
            .($remote ? "; copied to {$disk}:{$remote}" : '')
            .($removed ? "; removed {$removed} old backup(s)" : ''));

        return self::SUCCESS;
    }

    private function copyOffServer(string $zipPath, string $disk): string
    {
        $target = trim(config('admission.backups.remote_path'), '/').'/'.basename($zipPath);
        $stream = fopen($zipPath, 'r');
        try {
            if (! Storage::disk($disk)->writeStream($target, $stream)) {
                throw new RuntimeException('the disk refused the file');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $target;
    }

    private function dumpDatabase(string $tmp): string
    {
        $name = $this->option('connection') ?: config('database.default');
        $config = config("database.connections.{$name}");

        return match (DB::connection($name)->getDriverName()) {
            'mysql', 'mariadb' => $this->mysqlDump($config, "{$tmp}/database.sql"),
            'sqlite' => $this->sqliteCopy($name, $config, "{$tmp}/database.sqlite"),
            default => throw new RuntimeException('Backups support MySQL and SQLite only.'),
        };
    }

    private function mysqlDump(array $c, string $out): string
    {
        // The password goes in through the environment, never on the command line
        $process = new Process([
            config('admission.backups.mysqldump'),
            '--host='.$c['host'], '--port='.$c['port'], '--user='.$c['username'],
            '--single-transaction', '--routines', '--triggers', '--no-tablespaces',
            '--result-file='.$out, $c['database'],
        ], null, ['MYSQL_PWD' => (string) $c['password']], null, 600);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($out)) {
            throw new RuntimeException('mysqldump failed: '.trim($process->getErrorOutput() ?: $process->getOutput())
                .' (set BACKUP_MYSQLDUMP_PATH if mysqldump is not on the PATH)');
        }

        return $out;
    }

    private function sqliteCopy(string $name, array $c, string $out): string
    {
        if (($c['database'] ?? '') === ':memory:' || ! is_file($c['database'])) {
            throw new RuntimeException('There is no SQLite database file to back up.');
        }
        DB::connection($name)->statement('VACUUM INTO ?', [$out]); // consistent copy even while the app runs

        return $out;
    }

    /** Returns how many document files went in. */
    private function zip(string $zipPath, string $dump): int
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Can't create {$zipPath}.");
        }
        $password = config('admission.backups.password');
        $add = function (string $path, string $name) use ($zip, $password) {
            $zip->addFile($path, $name);
            if ($password) {
                $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password);
            }
        };
        $add($dump, basename($dump));

        // Documents on this server only. Files in the DMS or on S3 are backed up there.
        $count = 0;
        $diskName = config('admission.documents.local_disk');
        if (config("filesystems.disks.{$diskName}.driver") === 'local') {
            $disk = Storage::disk($diskName);
            foreach ($disk->allFiles('documents') as $file) {
                $add($disk->path($file), $file);
                $count++;
            }
        }
        $zip->setArchiveComment('PLP Online Admission backup '.now()->toIso8601String());
        $zip->close();

        return $count;
    }

    private function prune(string $dir, int $keep): int
    {
        $old = collect(File::glob("{$dir}/backup_*.zip"))->sort()->reverse()->slice(max(1, $keep));
        $old->each(fn ($f) => File::delete($f));

        return $old->count();
    }
}
