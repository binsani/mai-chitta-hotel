<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use ZipArchive;

#[Signature('hotel:backup {--database-only : Exclude uploads and environment configuration}')]
#[Description('Create a private, verified backup of the database, uploads and environment')]
class BackupHotelCommand extends Command
{
    public function handle(): int
    {
        $root = storage_path('backups');
        $name = 'hotel-backup-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4));
        $work = $root.'/.'.$name;
        $archive = $root.'/'.$name.'.zip';
        $previousMask = umask(0077);
        try {
            if (! class_exists(ZipArchive::class)) {
                throw new RuntimeException('PHP ZIP support is required.');
            }
            File::ensureDirectoryExists($work, 0700);
            chmod($root, 0700);
            $connectionName = config('database.default');
            $connection = config('database.connections.'.$connectionName);
            if ($connection['driver'] === 'sqlite') {
                $database = $work.'/database.sqlite';
                $pdo = DB::connection($connectionName)->getPdo();
                $pdo->exec('VACUUM INTO '.$pdo->quote($database));
            } elseif (in_array($connection['driver'], ['mysql', 'mariadb'], true)) {
                $database = $work.'/database.sql.gz';
                $stream = gzopen($database, 'wb9');
                if ($stream === false) {
                    throw new RuntimeException('Cannot open backup file.');
                }
                try {
                    $command = ['mysqldump', '--single-transaction', '--quick', '--skip-lock-tables', '--default-character-set=utf8mb4', '--host='.$connection['host'], '--port='.($connection['port'] ?? 3306), '--user='.$connection['username']];
                    if (! empty($connection['unix_socket'])) {
                        $command[] = '--socket='.$connection['unix_socket'];
                    }
                    $command[] = $connection['database'];
                    Process::timeout(300)->env(['MYSQL_PWD' => $connection['password'] ?? ''])->run($command, function (string $type, string $output) use ($stream): void {
                        if ($type === 'out' && gzwrite($stream, $output) === false) {
                            throw new RuntimeException('Cannot write database backup.');
                        }
                    })->throw();
                } finally {
                    gzclose($stream);
                }
                $verify = gzopen($database, 'rb');
                $hasData = $verify !== false && gzread($verify, 32) !== '';
                if ($verify !== false) {
                    gzclose($verify);
                }
                if (! $hasData) {
                    throw new RuntimeException('Database backup is empty.');
                }
            } else {
                throw new RuntimeException('Supported database drivers: MySQL, MariaDB and SQLite.');
            }
            $zip = new ZipArchive;
            if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Cannot create backup archive.');
            }
            try {
                $zip->addFile($database, basename($database));
                if (! $this->option('database-only')) {
                    $uploads = storage_path('app/public');
                    if (is_dir($uploads)) {
                        foreach (File::allFiles($uploads) as $file) {
                            if (! $file->isLink() && ! $zip->addFile($file->getRealPath(), 'uploads/'.str_replace('\\', '/', $file->getRelativePathname()))) {
                                throw new RuntimeException('Cannot archive uploaded file.');
                            }
                        }
                    }
                    if (is_file(base_path('.env')) && ! $zip->addFile(base_path('.env'), '.env')) {
                        throw new RuntimeException('Cannot archive environment.');
                    }
                }
                $zip->addFromString('manifest.json', json_encode(['created_at' => now()->toIso8601String(), 'database_driver' => $connection['driver'], 'database_file' => basename($database), 'database_sha256' => hash_file('sha256', $database), 'database_only' => (bool) $this->option('database-only')], JSON_THROW_ON_ERROR));
            } finally {
                if (! $zip->close()) {
                    throw new RuntimeException('Could not complete archive.');
                }
            }
            chmod($archive, 0600);
            $check = new ZipArchive;
            if ($check->open($archive, ZipArchive::CHECKCONS) !== true) {
                throw new RuntimeException('Archive integrity check failed.');
            }
            $copy = $check->getFromName(basename($database));
            $check->close();
            if ($copy === false || ! hash_equals(hash_file('sha256', $database), hash('sha256', $copy))) {
                throw new RuntimeException('Archived database checksum does not match.');
            }
            File::put($root.'/latest.json', json_encode(['created_at' => now()->toIso8601String(), 'archive' => basename($archive), 'sha256' => hash_file('sha256', $archive)], JSON_THROW_ON_ERROR));
            $this->info('Verified private backup created: '.$archive);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            File::delete($archive);
            report($exception);
            $this->error('Backup failed. No completed backup was recorded; inspect the application log.');

            return self::FAILURE;
        } finally {
            if (is_dir($work)) {
                File::deleteDirectory($work);
            }
            umask($previousMask);
        }
    }
}
