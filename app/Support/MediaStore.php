<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The one place that knows media files can live on more than one disk.
 *
 * Every upload lands on the local `public` disk and is processed there. When
 * `media.offload_disk` is set (e.g. Cloudflare R2), large files are moved
 * there afterwards (OffloadMedia / OffloadRelease) and their row's `disk`
 * column records it. Readers never ask "where is it?" themselves: they use
 * url() for links, localPath() for server-side processing (a cached copy for
 * remote files), download() for attachment responses and delete().
 *
 * See "Storage tiers" in ARCHITECTURE.md.
 */
class MediaStore
{
    public const LOCAL = 'public';

    public function __construct(private MediaCache $cache) {}

    public function disk(?string $name = null): Filesystem
    {
        return Storage::disk($name ?: self::LOCAL);
    }

    public function isLocal(?string $disk): bool
    {
        $disk = $disk ?: self::LOCAL;

        return config("filesystems.disks.{$disk}.driver") === 'local';
    }

    /** The configured offload disk, or null when offloading is off. */
    public function offloadDisk(): ?string
    {
        $disk = config('media.offload_disk');

        return $disk && config("filesystems.disks.{$disk}") ? $disk : null;
    }

    public function offloadEnabled(): bool
    {
        return $this->offloadDisk() !== null;
    }

    /** Whether a file of this type and size should leave the web host. */
    public function eligible(?string $mime, ?int $bytes): bool
    {
        if (!$this->offloadEnabled() || !$mime) {
            return false;
        }
        if ($mime === 'image/svg+xml') {
            return false; // never: SVGs rely on the local server's sandbox CSP
        }
        if ((int) $bytes < (int) config('media.offload_min_bytes')) {
            return false;
        }

        foreach (config('media.offload_mimes', []) as $pattern) {
            if ($pattern === $mime || (str_ends_with($pattern, '/*') && str_starts_with($mime, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    /** Key prefix deleted offloaded files are moved under ('' = delete outright). */
    public function trashPrefix(): string
    {
        $prefix = trim((string) config('media.trash_prefix'), '/');

        return $prefix === '' ? '' : $prefix . '/';
    }

    /** Whether $path on remote $disk is sitting in the trash (deleted, not yet purged). */
    public function inTrash(string $disk, string $path): bool
    {
        $trash = $this->trashPrefix();

        return $trash !== '' && !$this->isLocal($disk) && $this->disk($disk)->exists($trash . $path);
    }

    /** Moves $path on remote $disk back out of the trash. */
    public function restoreFromTrash(string $disk, string $path): bool
    {
        if (!$this->inTrash($disk, $path)) {
            return false;
        }
        $this->disk($disk)->move($this->trashPrefix() . $path, $path);

        return $this->disk($disk)->exists($path);
    }

    public function url(?string $disk, string $path): string
    {
        return $this->disk($disk)->url($path);
    }

    public function exists(?string $disk, ?string $path): bool
    {
        return $path !== null && $path !== '' && $this->disk($disk)->exists($path);
    }

    public function size(?string $disk, string $path): ?int
    {
        return $this->exists($disk, $path) ? $this->disk($disk)->size($path) : null;
    }

    /**
     * A real filesystem path to the file for server-side processing: the file
     * itself on a local disk, else a copy in the media cache. Null if it
     * doesn't exist.
     */
    public function localPath(?string $disk, ?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if ($this->isLocal($disk)) {
            $full = $this->disk($disk)->path($path);

            return file_exists($full) ? $full : null;
        }

        try {
            return $this->cache->get($disk, $path);
        } catch (Throwable $e) {
            Log::warning('Could not fetch offloaded media for local processing', [
                'disk' => $disk, 'path' => $path, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * An attachment download of the file as $name. A remote file is served by
     * a redirect to a short-lived signed URL, so the bytes come from the
     * offload disk rather than through the web host.
     */
    public function download(?string $disk, string $path, string $name, array $headers = []): Response
    {
        $store = $this->disk($disk);

        if ($this->isLocal($disk)) {
            return $store->download($path, $name, $headers);
        }

        abort_unless($store->exists($path), 404);

        $fallback = preg_replace('/[^\x20-\x7e]|[\/\\\\%"]/', '_', $name) ?: 'download';
        $url = $store->temporaryUrl($path, now()->addMinutes((int) config('media.download_url_minutes')), [
            'ResponseContentDisposition' => HeaderUtils::makeDisposition('attachment', $name, $fallback),
        ]);

        return redirect()->away($url);
    }

    /**
     * Copies a local file to the offload disk (under the same key, or $to),
     * verifying the size. Does not delete the local file: the caller does
     * that once the row points at the new disk.
     */
    public function copyToOffload(string $path, ?string $mime, string $from = self::LOCAL, ?string $to = null): bool
    {
        $to ??= $path;
        $target = $this->offloadDisk();
        if (!$target) {
            return false;
        }

        $source = $this->disk($from);
        if (!$source->exists($path)) {
            return false;
        }

        $expected = $source->size($path);
        $stream   = $source->readStream($path);

        try {
            $this->disk($target)->writeStream($to, $stream, array_filter([
                'ContentType'  => $mime,
                // Keys are UUID / version named and never rewritten.
                'CacheControl' => 'public, max-age=31536000, immutable',
            ]));
        } catch (Throwable $e) {
            Log::warning('Media offload upload failed', ['path' => $path, 'error' => $e->getMessage()]);

            return false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $ok = $this->disk($target)->exists($to) && $this->disk($target)->size($to) === $expected;
        if (!$ok) {
            Log::warning('Media offload verification failed', ['path' => $path, 'expected' => $expected]);
        }

        return $ok;
    }

    /** Copies a remote file back to the local disk (same key), verifying the size. */
    public function copyToLocal(string $disk, string $path): bool
    {
        $source = $this->disk($disk);
        if (!$source->exists($path)) {
            return false;
        }

        $expected = $source->size($path);
        $stream   = $source->readStream($path);
        try {
            $this->disk(self::LOCAL)->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $this->disk(self::LOCAL)->size($path) === $expected;
    }

    /**
     * Deletes files from a disk, and any cached copy of them. On a remote
     * (offload) disk with a trash prefix configured (`media.trash_prefix`),
     * files are moved under that prefix instead, a server-side copy, so a
     * restored older backup can still find them (`media:verify
     * --restore-trash`). An R2 lifecycle rule on the prefix purges them later.
     */
    public function delete(?string $disk, array|string $paths): void
    {
        $paths = array_values(array_filter((array) $paths));
        if (!$paths) {
            return;
        }

        $trash = $this->isLocal($disk) ? '' : $this->trashPrefix();
        if ($trash !== '') {
            foreach ($paths as $path) {
                try {
                    if ($this->disk($disk)->exists($path)) {
                        $this->disk($disk)->move($path, $trash . $path);
                    }
                } catch (Throwable $e) {
                    Log::warning('Media move to trash failed', ['disk' => $disk, 'path' => $path, 'error' => $e->getMessage()]);
                }
            }
        } else {
            try {
                $this->disk($disk)->delete($paths);
            } catch (Throwable $e) {
                Log::warning('Media delete failed', ['disk' => $disk, 'paths' => $paths, 'error' => $e->getMessage()]);
            }
        }

        if (!$this->isLocal($disk)) {
            foreach ($paths as $path) {
                $this->cache->forget($disk, $path);
            }
        }
    }
}
