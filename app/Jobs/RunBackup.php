<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\Backup\BackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Builds one backup archive in the queue worker (a full backup can take minutes). */
class RunBackup implements ShouldQueue
{
    use Queueable;

    public int $timeout = 14400;

    public int $tries = 1;

    public function __construct(public int $backupId)
    {
    }

    public function handle(BackupService $backups): void
    {
        $backup = Backup::find($this->backupId);

        if ($backup && $backup->status === 'queued') {
            $backups->run($backup);
            $backups->prune();
        }
    }
}
