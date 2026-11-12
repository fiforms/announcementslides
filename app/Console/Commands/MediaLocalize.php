<?php

namespace App\Console\Commands;

use App\Models\SlideAnnouncerRelease;
use App\Models\SlideMedia;
use App\Support\MediaStore;
use Illuminate\Console\Command;

/**
 * The way back from the offload tier: copies offloaded media and release
 * files back to the local `public` disk and points their rows at it, e.g.
 * before moving an install to a single host. Turn MEDIA_OFFLOAD_DISK off
 * first, or new uploads will keep being offloaded.
 *
 * With --relink, nothing is downloaded: rows are pointed at the local disk
 * wherever their file is already there, e.g. after copying a bucket mirror
 * (rclone backup) into storage/app/public during a restore without R2.
 */
class MediaLocalize extends Command
{
    protected $signature = 'media:localize
                            {--limit=0 : Stop after this many media rows / releases (0 = no limit)}
                            {--only= : "media" or "releases" (default: both)}
                            {--keep-remote : Leave the offloaded copies in place}
                            {--relink : Do not download; point rows at local files already in storage/app/public (e.g. a restored bucket mirror)}';

    protected $description = 'Copy offloaded media and release files back to local storage';

    public function handle(MediaStore $store): int
    {
        if ($this->option('relink')) {
            $this->line('Relinking rows to files already on the local disk (nothing is downloaded or deleted).');
        }

        if ($store->offloadEnabled()) {
            $this->warn('Offloading is still on (MEDIA_OFFLOAD_DISK): new uploads will keep being moved off this host.');
        }

        $limit = (int) $this->option('limit');
        $only  = $this->option('only');

        if ($only !== 'releases') {
            $this->info('Media rows brought back: ' . $this->media($store, $limit) . '.');
        }
        if ($only !== 'media') {
            $this->info('Releases brought back: ' . $this->releases($store, $limit) . '.');
        }

        return self::SUCCESS;
    }

    private function media(MediaStore $store, int $limit): int
    {
        $count = 0;

        // Purged media isn't in the bucket to bring back (restore it first with media:purge-archived --restore).
        foreach (SlideMedia::query()->whereNull('purged_at')->orderBy('id')->lazyById(200) as $media) {
            if ($limit && $count >= $limit) {
                break;
            }

            $remote = [];   // disk => paths
            $variants = $media->variants;
            $failed = false;

            if (!$store->isLocal($media->disk)) {
                if ($this->bringBack($store, $media->disk, $media->disk_path, $media->file_size)) {
                    $remote[$media->disk][] = $media->disk_path;
                    $media->disk = MediaStore::LOCAL;
                } else {
                    $failed = true;
                }
            }
            foreach ($variants ?? [] as $name => $v) {
                $disk = $v['disk'] ?? MediaStore::LOCAL;
                if (($v['disk_path'] ?? null) === $media->disk_path) {
                    $variants[$name]['disk'] = $media->disk;
                    continue;
                }
                if ($store->isLocal($disk) || empty($v['disk_path'])) {
                    continue;
                }
                if ($this->bringBack($store, $disk, $v['disk_path'], $v['file_size'] ?? null)) {
                    $remote[$disk][] = $v['disk_path'];
                    $variants[$name]['disk'] = MediaStore::LOCAL;
                } else {
                    $failed = true;
                }
            }

            if ($failed) {
                $this->warn("  #{$media->id}: some files could not be brought back (not copied, or not found locally with --relink); left where they are.");
            }
            if (!$remote) {
                continue;
            }

            $media->variants = $variants;
            $media->save();
            $count++;

            if (!$this->option('keep-remote') && !$this->option('relink')) {
                foreach ($remote as $disk => $paths) {
                    $store->delete($disk, $paths);
                }
            }
        }

        return $count;
    }

    private function releases(MediaStore $store, int $limit): int
    {
        $count = 0;

        foreach (SlideAnnouncerRelease::query()->where('disk', '!=', MediaStore::LOCAL)->orderBy('id')->lazyById(100) as $release) {
            if ($limit && $count >= $limit) {
                break;
            }
            $disk = $release->disk;
            if (!$this->bringBack($store, $disk, $release->disk_path, null)) {
                $this->warn("  {$release->kind} {$release->version}: could not be brought back (not copied, or not found locally with --relink).");
                continue;
            }

            $release->forceFill(['disk' => MediaStore::LOCAL])->save();
            $count++;

            if (!$this->option('keep-remote') && !$this->option('relink')) {
                $store->delete($disk, $release->disk_path);
            }
        }

        return $count;
    }

    /**
     * Gets $path onto the local disk: downloaded from $disk, or with --relink
     * only confirmed to be there already (and the expected size, if known).
     */
    private function bringBack(MediaStore $store, string $disk, string $path, ?int $size): bool
    {
        if (!$this->option('relink')) {
            return $store->copyToLocal($disk, $path);
        }

        $local = $store->disk(MediaStore::LOCAL);

        return $local->exists($path) && ($size === null || $local->size($path) === (int) $size);
    }
}
