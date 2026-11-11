<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;
use ZipArchive;

class BackupRestore extends Command
{
    protected $signature = 'backup:restore
        {file : Path to a backup .zip, or the name of one in the backup directory}
        {--db-only : Restore only the database}
        {--files-only : Restore only the uploaded files (full backups)}
        {--force : Do not ask for confirmation}
        {--no-safety-backup : Skip the database backup taken before restoring}';

    protected $description = 'Restore the database and/or files from a backup archive';

    public function handle(BackupService $backups): int
    {
        if ($this->option('db-only') && $this->option('files-only')) {
            $this->error('Choose at most one of --db-only and --files-only.');

            return self::INVALID;
        }

        $zip = new ZipArchive();
        $tmp = null;

        try {
            $path = $this->locate($backups, $this->argument('file'), $tmp);
            if ($zip->open($path) !== true) {
                throw new BackupException('Not a readable ZIP archive: '.$path);
            }

            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            $doDb = ! $this->option('files-only');
            $hasFiles = ($manifest['mode'] ?? null) === Backup::MODE_FULL;
            $doFiles = ! $this->option('db-only') && $hasFiles;

            $this->check($backups, $manifest, $zip, $doDb, $hasFiles);

            if ($this->option('files-only') && ! $hasFiles) {
                throw new BackupException('This is a database-only backup; it has no files to restore.');
            }

            $this->summary($manifest, $doDb, $doFiles);

            if (! $this->option('force') && ! $this->confirm('Continue? This replaces current data.', false)) {
                $this->line('Cancelled.');

                return self::SUCCESS;
            }

            if ($doDb && ! $this->option('no-safety-backup')) {
                $this->line('Taking a safety backup of the current database...');
                $safety = $backups->start(Backup::MODE_DATABASE, Backup::TRIGGER_PRE_RESTORE);
                $backups->run($safety);
                $this->line('  saved as '.$safety->filename);
            }

            Artisan::call('down', ['--retry' => 60]);
            try {
                if ($doDb) {
                    $this->restoreDatabase($backups, $zip, $manifest);
                }
                if ($doFiles) {
                    $this->restoreFiles($backups, $zip);
                }
            } finally {
                Artisan::call('up');
            }

            $backups->syncFromDisk();
            $this->info('Restore complete.');
            $this->afterNotes($backups, $manifest, $doDb);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $zip->close();
            if ($tmp) {
                @unlink($tmp);
            }
        }
    }

    private function locate(BackupService $backups, string $arg, ?string &$tmp): string
    {
        if (is_file($arg)) {
            return $arg;
        }

        $rel = $backups->dir().'/'.basename($arg);
        if (! $backups->disk()->exists($rel)) {
            throw new BackupException("File not found: {$arg}");
        }
        if (method_exists($backups->disk(), 'path')) {
            return $backups->disk()->path($rel);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'restore');
        file_put_contents($tmp, $backups->disk()->readStream($rel));

        return $tmp;
    }

    private function check(BackupService $backups, ?array $manifest, ZipArchive $zip, bool $doDb, bool $hasFiles): void
    {
        if (! is_array($manifest) || ($manifest['format'] ?? null) !== BackupService::FORMAT) {
            throw new BackupException('manifest.json is missing or has an unsupported format.');
        }

        if ($doDb) {
            $driver = $manifest['database']['driver'] ?? null;
            if ($driver !== $backups->dumper()->driver()) {
                throw new BackupException("This backup is from a {$driver} database; this install uses ".$backups->dumper()->driver().'.');
            }
            if ($zip->locateName('database.sql') === false) {
                throw new BackupException('database.sql is missing from the archive.');
            }

            $theirs = $manifest['latest_migration'] ?? null;
            $ours = $backups->latestMigrationFile();
            if ($theirs && $ours && strcmp($theirs, $ours) > 0) {
                throw new BackupException("This backup is from a newer version of the app (migration {$theirs}). Upgrade this install first.");
            }
        }
    }

    private function summary(array $manifest, bool $doDb, bool $doFiles): void
    {
        $this->line('Backup:   '.($manifest['created_at'] ?? '?').', '.($manifest['mode'] ?? '?').', app '.($manifest['app_version'] ?? '?'));
        $this->line('Restoring: '.collect([$doDb ? 'database' : null, $doFiles ? 'files' : null])->filter()->implode(' + '));
    }

    private function restoreDatabase(BackupService $backups, ZipArchive $zip, array $manifest): void
    {
        $sql = tempnam(sys_get_temp_dir(), 'restore-sql');

        try {
            $in = $zip->getStream('database.sql');
            if (! $in) {
                throw new BackupException('Could not read database.sql.');
            }
            $out = fopen($sql, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            $this->line('Loading the database...');
            $backups->dumper()->load($sql);
        } finally {
            @unlink($sql);
        }

        // An older backup may predate migrations this version has.
        if (($manifest['latest_migration'] ?? null) !== $backups->latestMigrationFile()) {
            $this->line('Applying newer migrations...');
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    private function restoreFiles(BackupService $backups, ZipArchive $zip): void
    {
        $this->line('Restoring files (existing files with the same name are overwritten)...');
        $roots = ['public' => config('filesystems.disks.public.root'), 'private' => config('filesystems.disks.local.root')];
        $n = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (! preg_match('#^files/(public|private)/(.+)$#', $name, $m) || str_ends_with($name, '/')) {
                continue;
            }
            // Never let an entry write outside its root.
            $segments = explode('/', $m[2]);
            if (in_array('..', $segments, true) || str_starts_with($m[2], '/')) {
                throw new BackupException("Unsafe path in archive: {$name}");
            }
            if ($m[1] === 'private' && $segments[0] === explode('/', $backups->dir())[0]) {
                continue;
            }

            $dest = rtrim($roots[$m[1]], '/').'/'.$m[2];
            if (! is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0775, true);
            }

            $in = $zip->getStream($name);
            $out = fopen($dest, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            $n++;
        }

        $this->line("  {$n} file(s) written.");
    }

    private function afterNotes(BackupService $backups, array $manifest, bool $doDb): void
    {
        if ($doDb && ($manifest['app_key_fingerprint'] ?? null) !== $backups->keyFingerprint()) {
            $this->warn('This install\'s APP_KEY differs from the one the backup was made with.');
            $this->warn('Everyone will have to sign in again, and widget API keys must be re-entered (Admin > Widgets).');
        }
        $this->line('Run `php artisan queue:restart` if a queue worker is running.');
    }
}
