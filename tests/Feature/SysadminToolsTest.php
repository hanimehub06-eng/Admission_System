<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Phase 10: sysadmin tools (D7, NFR-03). MySQL-only paths are checked by hand, see PROGRESS.md. */
class SysadminToolsTest extends TestCase
{
    public function test_backup_needs_a_real_database_file(): void
    {
        if (\DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('In-memory SQLite case only.');
        }
        // The test database lives in memory: nothing to back up, and it says so
        $this->artisan('app:backup')->expectsOutputToContain('There is no SQLite database file')->assertFailed();
    }

    public function test_backup_of_a_sqlite_file_includes_database_and_documents(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/AD-2026-00001/a.pdf', '%PDF-1.4');
        $dir = sys_get_temp_dir().'/adm_backup_test_'.uniqid();
        $file = $dir.'/db.sqlite';
        mkdir($dir);
        touch($file);
        config(['database.connections.filecopy' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => ''], 'admission.backups.path' => $dir.'/backups']);
        \DB::purge('filecopy');

        $this->artisan('app:backup', ['--connection' => 'filecopy'])->expectsOutputToContain('1 document files')->assertSuccessful();

        $zips = glob($dir.'/backups/backup_*.zip');
        $this->assertCount(1, $zips);
        $zip = new \ZipArchive;
        $zip->open($zips[0]);
        $this->assertNotFalse($zip->locateName('database.sqlite'));
        $this->assertNotFalse($zip->locateName('documents/AD-2026-00001/a.pdf'));
        $zip->close();
    }

    /** NFR-06: encrypted (AES-256) and copied off the server */
    public function test_backup_is_encrypted_and_copied_off_server(): void
    {
        Storage::fake('offsite');
        $dir = sys_get_temp_dir().'/adm_backup_test_'.uniqid();
        $file = $dir.'/db.sqlite';
        mkdir($dir);
        touch($file);
        config([
            'database.connections.filecopy' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => ''],
            'admission.backups.path' => $dir.'/backups',
            'admission.backups.password' => 'correct horse',
            'admission.backups.remote_disk' => 'offsite',
        ]);
        \DB::purge('filecopy');

        $this->artisan('app:backup', ['--connection' => 'filecopy'])->expectsOutputToContain('encrypted')->assertSuccessful();

        $zips = glob($dir.'/backups/backup_*.zip');
        $zip = new \ZipArchive;
        $zip->open($zips[0]);
        $this->assertSame(\ZipArchive::EM_AES_256, $zip->statName('database.sqlite')['encryption_method']);
        $this->assertFalse($zip->getFromName('database.sqlite'));   // unreadable without the password
        $zip->setPassword('correct horse');
        $this->assertNotFalse($zip->getFromName('database.sqlite'));
        $zip->close();
        Storage::disk('offsite')->assertExists('oas-backups/'.basename($zips[0]));
    }

    public function test_api_client_command_stores_only_a_hash(): void
    {
        $this->artisan('api-client:create', ['name' => 'enrollment', '--ips' => '10.0.0.5, 10.0.0.6'])
            ->expectsOutputToContain('X-API-Key:')->assertSuccessful();
        $client = \App\Models\ApiClient::where('name', 'enrollment')->first();
        $this->assertSame(['10.0.0.5', '10.0.0.6'], $client->allowed_ips);
        $this->assertSame(64, strlen($client->key_hash));
    }

    public function test_privileges_command(): void
    {
        if (\DB::getDriverName() === 'mysql') {
            // Append-only tables get SELECT + INSERT only (NFR-03)
            $this->artisan('app:db-privileges')
                ->expectsOutputToContain('GRANT SELECT, INSERT ON `'.\DB::getDatabaseName().'`.`audit_logs`')
                ->assertSuccessful();

            return;
        }
        $this->artisan('app:db-privileges')->expectsOutputToContain('This command is for MySQL')->assertFailed();
    }
}
