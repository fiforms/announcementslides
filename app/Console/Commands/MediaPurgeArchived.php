<?php

namespace App\Console\Commands;

use App\Models\Slide;
use App\Models\SlideMedia;
use App\Support\MediaStore;
use Illuminate\Console\Command;

/**
 * Frees bucket space by removing the offloaded files of slides archived (or
 * deleted) longer than `media.purge_archived_days` (default 180). The
 * database rows, thumbnails and posters stay, so the archive still lists the
 * slides, and each media row is marked `purged_at`. Files go to the bucket
 * trash first, then an R2 lifecycle rule deletes them; after that, the only
 * copy is the offline rclone mirror of the bucket.
 *
 * --restore brings purged files back wherever they are in the bucket again
 * (e.g. after `rclone copy <mirror> r2:<bucket>`) or still in its trash.
 * Un-archiving a slide does the same for that slide.
 */
class MediaPurgeArchived extends Command
{
    protected $signature = 'media:purge-archived
                            {--days= : Purge slides archived or deleted at least this many days ago (default: MEDIA_PURGE_ARCHIVED_DAYS, 180)}
                            {--dry-run : Only report what would be purged}
                            {--restore : Instead, bring purged media back where the files are in the bucket again or still in its trash}';

    protected $description = 'Remove long-archived slides\' offloaded media from the bucket (keeping their records)';

    public function handle(MediaStore $store): int
    {
        return $this->option('restore') ? $this->restore() : $this->purge($store);
    }

    private function purge(MediaStore $store): int
    {
        $days = (int) ($this->option('days') ?? config('media.purge_archived_days'));
        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }
        $cutoff = now()->subDays($days);
        $dry    = (bool) $this->option('dry-run');

        $query = SlideMedia::query()
            ->whereNull('purged_at')
            ->whereHas('slide', fn ($q) => $q->withTrashed()->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNotNull('expires_at')->where('expires_at', '<=', $cutoff))
                ->orWhere('deleted_at', '<=', $cutoff)))
            ->with(['slide' => fn ($q) => $q->withTrashed()]);

        $rows  = 0;
        $bytes = 0;
        foreach ($query->lazyById(200) as $media) {
            $remote = array_filter($media->versions(), fn ($v) => !empty($v['disk_path']) && !$store->isLocal($v['disk'] ?? null));
            if (!$remote) {
                continue; // only offloaded files are purged
            }
            // A slide still in use (e.g. its expiry moved back since the query) is skipped.
            if (!$media->slide || !$media->slide->isArchivedOrDeleted()) {
                continue;
            }

            $size = array_sum(array_map(fn ($v) => (int) ($v['file_size'] ?? 0), $remote));
            $this->line(sprintf('  %s "%s" (slide #%d, %s)', $dry ? 'Would purge' : 'Purging', $media->slide->title, $media->slide_id, $this->human($size)), verbosity: 'v');

            if (!$dry && !$media->purgeFromBucket()) {
                continue;
            }
            $rows++;
            $bytes += $size;
        }

        $this->info(($dry ? 'Would purge' : 'Purged') . " {$rows} media file(s), " . $this->human($bytes)
            . " (slides archived or deleted before {$cutoff->toDateString()}).");
        if (!$dry && $rows && $store->trashPrefix() !== '') {
            $this->line("They are in the bucket trash ({$store->trashPrefix()}) until its lifecycle rule removes them; after that, only your offline backup has them.");
        }

        return self::SUCCESS;
    }

    private function restore(): int
    {
        $restored = 0;
        $missing  = [];

        foreach (SlideMedia::query()->whereNotNull('purged_at')->with(['slide' => fn ($q) => $q->withTrashed()])->lazyById(200) as $media) {
            if ($media->restorePurged()) {
                $restored++;
            } else {
                $missing[] = [$media->slide_id, $media->slide?->title, $media->disk_path];
            }
        }

        $this->info("Restored {$restored} purged media file(s).");
        if ($missing) {
            $this->warn(count($missing) . ' are not in the bucket or its trash. Upload them from your backup mirror, e.g.');
            $this->line('  rclone copy /backups/r2-mirror r2:<bucket> --header-upload "Cache-Control: public, max-age=31536000, immutable"');
            $this->line('then run this again. Still missing:');
            $this->table(['Slide', 'Title', 'Key'], $missing);
        }

        return $missing ? self::FAILURE : self::SUCCESS;
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
