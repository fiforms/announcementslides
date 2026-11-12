<?php

namespace App\Console\Commands;

use App\Jobs\OffloadMedia;
use App\Jobs\OffloadRelease;
use App\Models\SlideAnnouncerRelease;
use App\Models\SlideMedia;
use App\Support\MediaStore;
use Illuminate\Console\Command;

/**
 * Backfill for the offload tier: moves existing large media files (and
 * release files) that qualify to the offload disk. New uploads are offloaded
 * automatically after their thumbnail is made; this catches everything that
 * was uploaded before offloading was switched on.
 */
class MediaOffload extends Command
{
    protected $signature = 'media:offload
                            {--dry-run : Only report what would be moved}
                            {--limit=0 : Stop after this many media rows / releases (0 = no limit)}
                            {--only= : "media" or "releases" (default: both)}
                            {--now : Run each move immediately instead of queueing it}';

    protected $description = 'Move existing large media and release files to the offload disk (e.g. Cloudflare R2)';

    public function handle(MediaStore $store): int
    {
        if (!$store->offloadEnabled()) {
            $this->error('Offloading is off: set MEDIA_OFFLOAD_DISK (e.g. "r2") and configure that disk first.');

            return self::FAILURE;
        }

        $only  = $this->option('only');
        $limit = (int) $this->option('limit');
        $dry   = (bool) $this->option('dry-run');

        if ($only !== 'releases') {
            [$n, $bytes] = $this->media($store, $limit, $dry);
            $this->info(($dry ? 'Would move' : ($this->option('now') ? 'Moved' : 'Queued')) . " {$n} media row(s), " . $this->human($bytes) . '.');
        }

        if ($only !== 'media') {
            if (!config('media.offload_releases')) {
                $this->line('Release offloading is off (MEDIA_OFFLOAD_RELEASES=false).');
            } else {
                [$n, $bytes] = $this->releases($store, $limit, $dry);
                $this->info(($dry ? 'Would move' : ($this->option('now') ? 'Moved' : 'Queued')) . " {$n} release(s), " . $this->human($bytes) . '.');
            }
        }

        return self::SUCCESS;
    }

    /** @return array{int, int} rows, bytes */
    private function media(MediaStore $store, int $limit, bool $dry): array
    {
        $count = 0;
        $bytes = 0;

        foreach (SlideMedia::query()->orderBy('id')->lazyById(200) as $media) {
            if ($limit && $count >= $limit) {
                break;
            }

            $size = 0;
            foreach ($media->versions() as $v) {
                if ($store->isLocal($v['disk'] ?? null) && $store->eligible($v['mime_type'] ?? null, $v['file_size'] ?? null)) {
                    $size += (int) $v['file_size'];
                }
            }
            if ($size === 0) {
                continue;
            }

            $count++;
            $bytes += $size;
            if ($dry) {
                $this->line("  #{$media->id} {$media->disk_path} (" . $this->human($size) . ')', verbosity: 'v');
                continue;
            }
            $this->option('now') ? OffloadMedia::dispatchSync($media) : OffloadMedia::dispatch($media);
        }

        return [$count, $bytes];
    }

    /** @return array{int, int} releases, bytes */
    private function releases(MediaStore $store, int $limit, bool $dry): array
    {
        $count = 0;
        $bytes = 0;

        foreach (SlideAnnouncerRelease::query()->where('disk', MediaStore::LOCAL)->orderBy('id')->lazyById(100) as $release) {
            if ($limit && $count >= $limit) {
                break;
            }
            $size = $release->fileSize();
            if ($size === null) {
                continue;
            }

            $count++;
            $bytes += $size;
            if ($dry) {
                $this->line("  {$release->kind} {$release->version} {$release->disk_path} (" . $this->human($size) . ')', verbosity: 'v');
                continue;
            }
            $this->option('now') ? OffloadRelease::dispatchSync($release) : OffloadRelease::dispatch($release);
        }

        return [$count, $bytes];
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, 1) . ' ' . $units[$i];
    }
}
