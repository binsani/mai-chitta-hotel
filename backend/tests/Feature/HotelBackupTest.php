<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PDO;
use Tests\TestCase;
use ZipArchive;

class HotelBackupTest extends TestCase
{
    public function test_database_backup_can_be_restored_and_matches_its_checksum(): void
    {
        $originalStorage = storage_path();
        $directory = sys_get_temp_dir().'/hotel-backup-test-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        $this->app->useStoragePath($directory);
        config(['database.default' => 'backup_test', 'database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $directory.'/source.sqlite', 'foreign_key_constraints' => true]]);
        touch($directory.'/source.sqlite');
        DB::connection('backup_test')->statement('CREATE TABLE verification (value TEXT)');
        DB::connection('backup_test')->insert('INSERT INTO verification (value) VALUES (?)', ['Hotel backup restore check']);
        try {
            $this->artisan('hotel:backup', ['--database-only' => true])->assertSuccessful();

            $archives = glob($directory.'/backups/hotel-backup-*.zip');
            $this->assertCount(1, $archives);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($archives[0], ZipArchive::CHECKCONS));
            $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $database = $zip->getFromName('database.sqlite');
            $this->assertSame($manifest['database_sha256'], hash('sha256', $database));
            $this->assertFalse($zip->getFromName('.env'));
            $zip->close();
            file_put_contents($directory.'/restored.sqlite', $database);
            $restored = new PDO('sqlite:'.$directory.'/restored.sqlite');
            $this->assertSame('Hotel backup restore check', $restored->query('SELECT value FROM verification')->fetchColumn());
            $restored = null;
        } finally {
            DB::purge('backup_test');
            $this->app->useStoragePath($originalStorage);
            File::deleteDirectory($directory);
        }
    }

    public function test_failed_dump_does_not_leave_a_partial_archive_or_replace_the_last_success(): void
    {
        $originalStorage = storage_path();
        $directory = sys_get_temp_dir().'/hotel-backup-failure-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory.'/backups');
        file_put_contents($directory.'/backups/latest.json', 'previous successful backup');
        $this->app->useStoragePath($directory);
        config(['database.default' => 'backup_test', 'database.connections.backup_test' => ['driver' => 'mysql', 'host' => 'localhost', 'username' => 'test', 'password' => 'test-only', 'database' => 'test']]);
        Process::fake(['*' => Process::result(errorOutput: 'Dump failed', exitCode: 1)]);
        try {
            $this->artisan('hotel:backup', ['--database-only' => true])->assertFailed();

            $this->assertSame([], glob($directory.'/backups/hotel-backup-*.zip'));
            $this->assertSame('previous successful backup', file_get_contents($directory.'/backups/latest.json'));
            $this->assertSame([], glob($directory.'/backups/.hotel-backup-*'));
        } finally {
            $this->app->useStoragePath($originalStorage);
            File::deleteDirectory($directory);
        }
    }
}
