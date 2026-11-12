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
 */
class MediaLocalize extends Command
{
    protected $signature = 'media:localize
                            {--limit=0 : Stop after this many media rows / releases (0 = no limit)}
                            {--only= : "media" or "releases" (default: both)}
                            {--keep-remote : Leave the offloaded copies in place}';

    protected $description = 'Copy offloaded media and release files back to local storage';

    public function handle(MediaStore $store): int
    {
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

        foreach (SlideMedia::query()->orderBy('id')->lazyById(200) as $media) {
            if ($limit && $count >= $limit) {
                break;
            }

            $remote = [];   // disk => paths
            $variants = $media->variants;
            $failed = false;

            if (!$store->isLocal($media->disk)) {
                if ($store->copyToLocal($media->disk, $media->disk_path)) {
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
                if ($store->copyToLocal($disk, $v['disk_path'])) {
                    $remote[$disk][] = $v['disk_path'];
                    $variants[$name]['disk'] = MediaStore::LOCAL;
                } else {
                    $failed = true;
                }
            }

            if ($failed) {
                $this->warn("  #{$media->id}: some files could not be copied back (left where they are).");
            }
            if (!$remote) {
                continue;
            }

            $media->variants = $variants;
            $media->save();
            $count++;

            if (!$this->option('keep-remote')) {
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
            if (!$store->copyToLocal($disk, $release->disk_path)) {
                $this->warn("  {$release->kind} {$release->version}: could not be copied back.");
                continue;
            }

            $release->forceFill(['disk' => MediaStore::LOCAL])->save();
            $count++;

            if (!$this->option('keep-remote')) {
                $store->delete($disk, $release->disk_path);
            }
        }

        return $count;
    }
}
