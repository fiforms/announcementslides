<?php

namespace App\Jobs;

use App\Models\SlideAnnouncerRelease;
use App\Support\MediaStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Moves a Slide Announcer release file (app bundle, OS bundle, disk image)
 * to the offload disk when offloading is configured and
 * `media.offload_releases` is on. Devices and the download pages pick up the
 * new location through SlideAnnouncerRelease::url().
 *
 * Until this finishes, the release is held back from devices
 * (SlideAnnouncerRelease::awaitingOffload, `media.releases_wait_for_offload`)
 * so a fleet never downloads it from the web host. If the copy fails, the
 * release stays held back. Retry with `media:offload --only=releases`.
 *
 * Release paths are named by version, so a deleted and re-published version
 * would reuse a key that a CDN may have cached as immutable. The offloaded
 * copy's key therefore includes part of the file's sha256.
 */
class OffloadRelease implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 7200;

    public array $backoff = [60, 300];

    public function __construct(public SlideAnnouncerRelease $release) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('offload-release-' . $this->release->id))->releaseAfter(60)->expireAfter(7200)];
    }

    public function handle(MediaStore $store): void
    {
        $release = $this->release->fresh();
        $target  = $store->offloadDisk();
        if (!$release || !$target || !config('media.offload_releases') || !$store->isLocal($release->disk)) {
            return;
        }

        $local = $release->disk_path;
        if (!$store->exists(MediaStore::LOCAL, $local)) {
            return;
        }

        $key = self::offloadKey($local, $release->sha256);
        if (!$store->copyToOffload($local, 'application/octet-stream', MediaStore::LOCAL, $key)) {
            // Retried by the queue (tries/backoff); the release stays held back meanwhile.
            throw new \RuntimeException("Could not copy release {$release->id} to the offload disk.");
        }

        $release->forceFill(['disk' => $target, 'disk_path' => $key])->save();
        $store->delete(MediaStore::LOCAL, $local);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Release offload failed; devices will not be offered it until it succeeds (media:offload --only=releases)', [
            'release_id' => $this->release->id,
            'version'    => $this->release->version,
            'error'      => $e?->getMessage(),
        ]);
    }

    /** "…/0.4.1.raucb" → "…/0.4.1-<sha12>.raucb" (multi-part extensions kept whole). */
    public static function offloadKey(string $path, ?string $sha256): string
    {
        $tag = substr($sha256 ?: bin2hex(random_bytes(6)), 0, 12);

        return preg_match('/^(.*?)(\.(?:tar\.gz|img\.xz|[A-Za-z0-9]+))$/', $path, $m)
            ? "{$m[1]}-{$tag}{$m[2]}"
            : "{$path}-{$tag}";
    }
}
