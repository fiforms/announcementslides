<?php

namespace App\Jobs;

use App\Models\SlideMedia;
use App\Services\VideoFrameExtractor;
use App\Support\MediaStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Moves a media row's large files (the active version and any stored
 * variant) from the local `public` disk to the offload disk, when offloading
 * is configured and each file qualifies (MediaStore::eligible: type
 * allowlist, size threshold, never SVG). Thumbnails and posters stay local.
 *
 * Order matters: copy and verify, then point the row at the new disk, then
 * delete the local copy. A video is only moved once its poster frame exists,
 * so its thumbnail can always be rebuilt without fetching it back.
 *
 * Idempotent: it re-reads the row and skips anything already moved.
 */
class OffloadMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    public array $backoff = [60, 300];

    public function __construct(public SlideMedia $media) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('offload-media-' . $this->media->id))->releaseAfter(60)->expireAfter(3600)];
    }

    public function handle(MediaStore $store, VideoFrameExtractor $frames): void
    {
        $media  = $this->media->fresh();
        $target = $store->offloadDisk();
        if (!$media || !$target) {
            return;
        }

        $moved = [];

        // The active version.
        if ($this->qualifies($store, $media, $media->disk, $media->disk_path, $media->mime_type, $media->file_size)) {
            if ($media->isVideo() && !(new GenerateThumbnail($media, offloadAfter: false))->ensurePoster($media, $frames)) {
                Log::warning('Not offloading a video with no poster frame', ['slide_media_id' => $media->id]);
            } elseif ($store->copyToOffload($media->disk_path, $media->mime_type)) {
                $moved[] = $media->disk_path;
                $media->disk = $target;
            }
        }

        // Stored variants (e.g. the original under an AI upscale).
        $variants = $media->variants;
        foreach ($variants ?? [] as $name => $v) {
            $path = $v['disk_path'] ?? null;
            if ($path === $media->disk_path) {
                $variants[$name]['disk'] = $media->disk; // the active version's own snapshot
                continue;
            }
            if (!$this->qualifies($store, $media, $v['disk'] ?? MediaStore::LOCAL, $path, $v['mime_type'] ?? null, $v['file_size'] ?? null)) {
                continue;
            }
            if ($store->copyToOffload($path, $v['mime_type'] ?? null)) {
                $moved[] = $path;
                $variants[$name]['disk'] = $target;
            }
        }

        if (!$moved) {
            return;
        }

        $media->variants = $variants;
        $media->save();

        $store->delete(MediaStore::LOCAL, $moved);
    }

    private function qualifies(MediaStore $store, SlideMedia $media, ?string $disk, ?string $path, ?string $mime, ?int $bytes): bool
    {
        if (!$path || !$store->isLocal($disk) || !$store->eligible($mime, $bytes)) {
            return false;
        }

        // Never pull a file out from under another row that uses it too.
        return !SlideMedia::where('disk_path', $path)->whereKeyNot($media->id)->exists();
    }
}
