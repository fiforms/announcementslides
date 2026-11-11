<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

class BackupCreate extends Command
{
    protected $signature = 'backup:create
        {--full : Include uploaded files (slides, thumbnails, widgets), not just the database}
        {--tag=cli : What to record as the trigger (cli, scheduled, pre-migrate)}
        {--prune : Apply the retention settings afterwards}
        {--if-migrations-pending : Only back up when this is an existing install with migrations to apply (used by the Docker entrypoint)}';

    protected $description = 'Create a backup archive (database, or database plus files with --full)';

    public function handle(BackupService $backups): int
    {
        $trigger = $this->option('tag');
        $valid = [Backup::TRIGGER_CLI, Backup::TRIGGER_SCHEDULED, Backup::TRIGGER_PRE_MIGRATE];
        if (! in_array($trigger, $valid, true)) {
            $this->error('--tag must be one of: '.implode(', ', $valid));

            return self::INVALID;
        }

        try {
            if ($this->option('if-migrations-pending')) {
                if (! config('backup.before_migrate')) {
                    $this->line('Pre-migration backup disabled (BACKUP_BEFORE_MIGRATE=false).');

                    return self::SUCCESS;
                }

                $pending = $backups->pendingMigrations();
                if (! $pending) {
                    $this->line($pending === null ? 'Fresh install: no backup needed.' : 'No pending migrations: no backup needed.');

                    return self::SUCCESS;
                }
                $this->line("{$pending} pending migration(s): backing up the database first.");
            }

            $backup = $backups->start($this->option('full') ? Backup::MODE_FULL : Backup::MODE_DATABASE, $trigger);
            $backups->run($backup);

            if ($this->option('prune')) {
                $backups->prune();
            }
        } catch (Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Backup written: %s (%s)', $backup->filename, number_format($backup->size / 1048576, 2).' MB'));
        $this->line('Location: '.config('backup.disk').':'.$backups->dir().'/'.$backup->filename);

        return self::SUCCESS;
    }
}
