<?php

namespace App\Support;

use App\Jobs\PruneLibraryFiles;
use App\Models\LibraryItem;
use App\Models\SlideMedia;
use Illuminate\Support\Facades\Storage;

/**
 * Content-addressed storage for the slide library.
 *
 * A library background is stored once, at `library/bg/<aa>/<sha256>.<ext>`
 * (thumbnail and poster under `library/thumbs|posters/<sha256>.jpg`), however
 * many library items, slides and resize variants point at it. Slide rows copy
 * those paths, so nothing is duplicated, and SlideMedia::deleteVersionFiles()
 * never deletes anything under `library/`: instead sweep() removes files that
 * no library item or media row references any more.
 */
class LibraryFiles
{
    public const PREFIX = 'library/';

    public static function isShared(?string $path): bool
    {
        return $path !== null && str_starts_with($path, self::PREFIX);
    }

    public static function backgroundPath(string $sha256, string $ext): string
    {
        return self::PREFIX . 'bg/' . substr($sha256, 0, 2) . "/{$sha256}.{$ext}";
    }

    public static function audioPath(string $sha256, string $ext): string
    {
        return self::PREFIX . 'audio/' . substr($sha256, 0, 2) . "/{$sha256}.{$ext}";
    }

    public static function thumbnailPath(string $sha256): string
    {
        return self::PREFIX . "thumbs/{$sha256}.jpg";
    }

    public static function posterPath(string $sha256): string
    {
        return self::PREFIX . "posters/{$sha256}.jpg";
    }

    /** Queue a sweep after something that may have freed a shared file was deleted. */
    public static function pruneSoon(): void
    {
        // Delayed so the row delete that triggered it has committed.
        PruneLibraryFiles::dispatch()->delay(now()->addMinute());
    }

    /** Every shared path some row still points at. @return array<string, true> */
    public function referencedPaths(): array
    {
        $paths = [];
        $add = function (?string $p) use (&$paths) {
            if (self::isShared($p) && !str_starts_with($p, self::PREFIX . 'templates/')) {
                $paths[$p] = true;
            }
        };

        LibraryItem::whereIn('kind', [LibraryItem::BACKGROUND, LibraryItem::AUDIO])->get(['disk_path', 'thumbnail_path', 'poster_path'])
            ->each(fn ($i) => array_map($add, [$i->disk_path, $i->thumbnail_path, $i->poster_path]));

        SlideMedia::where(fn ($q) => $q
            ->where('disk_path', 'like', self::PREFIX . '%')
            ->orWhere('thumbnail_path', 'like', self::PREFIX . '%')
            ->orWhere('poster_path', 'like', self::PREFIX . '%')
            // JSON escapes "/", so variants can't be matched with LIKE; rows that
            // have any (resized images) are few and filtered below.
            ->orWhereNotNull('variants'))
            ->get(['disk_path', 'thumbnail_path', 'poster_path', 'variants'])
            ->each(function ($m) use ($add) {
                array_map($add, [$m->disk_path, $m->thumbnail_path, $m->poster_path]);
                foreach ($m->variants ?? [] as $v) {
                    array_map($add, [$v['disk_path'] ?? null, $v['thumbnail_path'] ?? null, $v['poster_path'] ?? null]);
                }
            });

        return $paths;
    }

    /**
     * Deletes unreferenced shared files from the local disk and the offload
     * disk (when configured). Files newer than $minAgeSeconds are kept, so an
     * upload that hasn't been attached yet isn't swept. Returns what it deleted.
     *
     * @return string[]
     */
    public function sweep(int $minAgeSeconds = 600): array
    {
        $store = app(MediaStore::class);
        $referenced = $this->referencedPaths();
        $deleted = [];

        $disks = array_unique(array_filter([MediaStore::LOCAL, $store->offloadDisk()]));
        foreach ($disks as $name) {
            $disk = Storage::disk($name);
            foreach (['bg', 'audio', 'thumbs', 'posters'] as $dir) {
                foreach ($disk->allFiles(self::PREFIX . $dir) as $path) {
                    if (isset($referenced[$path]) || $disk->lastModified($path) > time() - $minAgeSeconds) {
                        continue;
                    }
                    $disk->delete($path);
                    $deleted[] = "{$name}:{$path}";
                }
            }
        }

        return $deleted;
    }
}
