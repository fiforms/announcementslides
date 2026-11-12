<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Local copies of offloaded files (see MediaStore), for server-side work that
 * needs a real path: ffmpeg, GD, ZIP/REVELation exports, compositing.
 *
 * Offloaded objects are UUID-named and never rewritten, so a cached copy is
 * never stale. A file's mtime is its last-use time (touched on every hit).
 * prune() evicts least recently used files until the cache is under
 * `media.cache.max_bytes`, but never one used within
 * `media.cache.min_age_minutes`: a job may be reading it right now, so the
 * cache may run over its target for a while rather than pull it away.
 */
class MediaCache
{
    private const LOCK_DIR = '.locks';

    public function root(): string
    {
        return rtrim(config('media.cache.path'), '/');
    }

    /** Where $disk's $path is (or would be) cached. */
    public function pathFor(string $disk, string $path): string
    {
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..') || str_contains($disk, '/') || str_contains($disk, '..')) {
            throw new RuntimeException("Refusing to cache unsafe path [{$disk}:{$path}].");
        }

        return $this->root() . "/{$disk}/{$path}";
    }

    /**
     * A local copy of $disk's $path, fetched on first use. Null if the object
     * does not exist.
     */
    public function get(string $disk, string $path): ?string
    {
        $dest = $this->pathFor($disk, $path);
        if (is_file($dest)) {
            @touch($dest);

            return $dest;
        }

        $dir = dirname($dest);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // One fetch per file: a second job waiting on the same object picks
        // up the finished copy instead of downloading it again.
        $lock = $this->lock($disk, $path);
        try {
            if (is_file($dest)) {
                @touch($dest);

                return $dest;
            }

            $source = Storage::disk($disk);
            if (!$source->exists($path)) {
                return null;
            }

            $in = $source->readStream($path);
            if (!$in) {
                return null;
            }

            $part = $dest . '.part.' . getmypid();
            $out  = fopen($part, 'wb');
            try {
                stream_copy_to_stream($in, $out);
            } finally {
                fclose($out);
                if (is_resource($in)) {
                    fclose($in);
                }
            }

            if (!rename($part, $dest)) {
                @unlink($part);

                return null;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->pruneIfOver();

        return $dest;
    }

    public function forget(string $disk, string $path): void
    {
        $file = $this->pathFor($disk, $path);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** Total bytes of cached files (excluding in-flight downloads and locks). */
    public function size(): int
    {
        return array_sum(array_column($this->entries(), 'size'));
    }

    public function pruneIfOver(): void
    {
        if ($this->size() > (int) config('media.cache.max_bytes')) {
            $this->prune();
        }
    }

    /**
     * Evicts least recently used files until the cache is under its target,
     * skipping any used within the minimum age. With $all, empties the cache
     * regardless of age (an operator's choice, e.g. to reclaim disk).
     *
     * @return array{evicted: int, freed: int, size: int}
     */
    public function prune(bool $all = false): array
    {
        $minAge = time() - 60 * (int) config('media.cache.min_age_minutes');
        $max    = (int) config('media.cache.max_bytes');

        // Leftovers of a fetch that died part-way, and lock files nobody has
        // used for a while.
        foreach ($this->files() as $file) {
            $isScratch = str_contains($file->getFilename(), '.part.')
                || str_contains($file->getPathname(), '/' . self::LOCK_DIR . '/');
            if ($isScratch && ($all || $file->getMTime() < $minAge)) {
                @unlink($file->getPathname());
            }
        }

        $entries = $this->entries();
        usort($entries, fn ($a, $b) => $a['mtime'] <=> $b['mtime']);
        $total = array_sum(array_column($entries, 'size'));

        $evicted = 0;
        $freed   = 0;
        foreach ($entries as $entry) {
            if (!$all && $total <= $max) {
                break;
            }
            if (!$all && $entry['mtime'] >= $minAge) {
                continue; // in recent use: keep, even over target
            }
            if (@unlink($entry['path'])) {
                $total -= $entry['size'];
                $freed += $entry['size'];
                $evicted++;
            }
        }

        $this->removeEmptyDirs($this->root());

        return ['evicted' => $evicted, 'freed' => $freed, 'size' => $total];
    }

    /** @return list<array{path: string, size: int, mtime: int}> */
    private function entries(): array
    {
        $entries = [];
        foreach ($this->files() as $file) {
            if (str_contains($file->getFilename(), '.part.')
                || str_contains($file->getPathname(), '/' . self::LOCK_DIR . '/')) {
                continue;
            }
            $entries[] = ['path' => $file->getPathname(), 'size' => $file->getSize(), 'mtime' => $file->getMTime()];
        }

        return $entries;
    }

    /** @return iterable<\SplFileInfo> */
    private function files(): iterable
    {
        $root = $this->root();
        if (!is_dir($root)) {
            return [];
        }

        clearstatcache();

        return new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
    }

    /** @return resource */
    private function lock(string $disk, string $path)
    {
        $dir = $this->root() . '/' . self::LOCK_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $handle = fopen($dir . '/' . sha1("{$disk}:{$path}"), 'c');
        if (!$handle || !flock($handle, LOCK_EX)) {
            throw new RuntimeException("Could not lock media cache entry [{$disk}:{$path}].");
        }

        return $handle;
    }

    private function removeEmptyDirs(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $empty = true;
        foreach (scandir($dir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = "{$dir}/{$name}";
            if (!is_dir($child) || !$this->removeEmptyDirs($child) || !@rmdir($child)) {
                $empty = false;
            }
        }

        return $empty;
    }
}
