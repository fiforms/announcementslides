<?php

namespace App\Console\Commands;

use App\Support\MediaInventory;
use App\Support\MediaStore;
use Illuminate\Console\Command;

/**
 * Checks that every file the database refers to exists where its row says:
 * on the local disk or in the offload bucket. Run it after a restore, or any
 * time, to find missing files before viewers do. Offloaded files that were
 * deleted but are still in the bucket's trash can be moved back with
 * --restore-trash. With --mirror, also checks a local copy of the bucket
 * (e.g. an rclone backup folder) holds every offloaded file.
 */
class MediaVerify extends Command
{
    protected $signature = 'media:verify
                            {--mirror= : A local folder mirroring the bucket (e.g. an rclone copy); check it holds every offloaded file}
                            {--restore-trash : Move offloaded files found in the bucket trash back into place}
                            {--skip-derived : Skip thumbnails, posters and composites (they can be rebuilt with slide:resync-thumbnails)}';

    protected $description = 'Check that every media and release file the database refers to exists';

    public function handle(MediaInventory $inventory, MediaStore $store): int
    {
        $mirror = $this->option('mirror') ? rtrim($this->option('mirror'), '/') : null;
        if ($mirror !== null && !is_dir($mirror)) {
            $this->error("No such folder: {$mirror}");

            return self::FAILURE;
        }

        $checked   = 0;
        $missing   = [];   // rows of [kind, where, path, owner]
        $restored  = 0;
        $inTrash   = 0;
        $mirrorGap = [];

        foreach ($inventory->files() as $file) {
            if ($this->option('skip-derived') && in_array($file['kind'], ['thumbnail', 'poster', 'composite'], true)) {
                continue;
            }
            $checked++;
            $remote = !$store->isLocal($file['disk']);

            if ($remote && $mirror !== null) {
                $copy = "{$mirror}/{$file['path']}";
                if (!is_file($copy) || ($file['size'] !== null && filesize($copy) !== $file['size'])) {
                    $mirrorGap[] = [$file['kind'], $file['path'], $file['owner']];
                }
            }

            if ($store->exists($file['disk'], $file['path'])) {
                continue;
            }

            if ($remote && $store->inTrash($file['disk'], $file['path'])) {
                if ($this->option('restore-trash') && $store->restoreFromTrash($file['disk'], $file['path'])) {
                    $restored++;
                    continue;
                }
                $inTrash++;
                $missing[] = [$file['kind'], "{$file['disk']} (in trash)", $file['path'], $file['owner']];
                continue;
            }

            $missing[] = [$file['kind'], $file['disk'], $file['path'], $file['owner']];
        }

        $this->info("Checked {$checked} file(s).");
        if ($restored) {
            $this->info("Moved {$restored} file(s) back out of the bucket trash.");
        }

        if ($missing) {
            $this->warn(count($missing) . ' file(s) missing:');
            $this->table(['Kind', 'Disk', 'Path', 'Referenced by'], $missing);
            if ($inTrash) {
                $this->line("{$inTrash} of them are in the bucket trash: run again with --restore-trash to move them back.");
            }
            if (collect($missing)->contains(fn ($m) => in_array($m[0], ['thumbnail', 'poster', 'composite'], true))) {
                $this->line('Missing thumbnails, posters and composites can be rebuilt: php artisan slide:resync-thumbnails --all --posters');
            }
        } else {
            $this->info('No files missing.');
        }

        if ($mirror !== null) {
            if ($mirrorGap) {
                $this->warn(count($mirrorGap) . " offloaded file(s) are not in the mirror ({$mirror}):");
                $this->table(['Kind', 'Key', 'Referenced by'], $mirrorGap);
            } else {
                $this->info("The mirror holds every offloaded file.");
            }
        }

        return $missing || $mirrorGap ? self::FAILURE : self::SUCCESS;
    }
}
