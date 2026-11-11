<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

class BackupList extends Command
{
    protected $signature = 'backup:list';

    protected $description = 'List backup archives';

    public function handle(BackupService $backups): int
    {
        $backups->syncFromDisk();

        $rows = Backup::where('status', 'completed')->orderByDesc('id')->get()->map(fn (Backup $b) => [
            $b->filename, $b->mode, $b->trigger, number_format($b->size / 1048576, 2).' MB', $b->finished_at?->toDateTimeString(),
        ]);

        $this->table(['File', 'Mode', 'Trigger', 'Size', 'Finished'], $rows);

        return self::SUCCESS;
    }
}
