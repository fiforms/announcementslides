<?php

namespace App\Jobs;

use App\Models\LibraryItem;
use App\Services\VideoFrameExtractor;
use App\Support\LibraryFiles;
use App\Support\MediaStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Builds a library background's 600px thumbnail (and, for a video, its
 * full-size poster frame) under content-addressed keys on the local disk,
 * then hands the item to OffloadLibraryItem. Slides built from the item copy
 * these paths, so they get their thumbnails for free.
 */
class GenerateLibraryThumbnail implements ShouldQueue
{
    use Queueable;

    public function __construct(public LibraryItem $item) {}

    public function handle(VideoFrameExtractor $frames): void
    {
        $item = $this->item->fresh();
        if (!$item || $item->isTemplate() || !$item->sha256) {
            return;
        }

        $disk = Storage::disk(MediaStore::LOCAL);
        $source = $item->localPath();
        if (!$source) {
            return;
        }

        $thumbPath = LibraryFiles::thumbnailPath($item->sha256);
        $posterPath = null;

        if ($item->isVideo()) {
            $posterPath = LibraryFiles::posterPath($item->sha256);
            if (!$disk->exists($posterPath) && !$frames->extract($source, $disk->path($posterPath), null, ['library_item_id' => $item->id])) {
                return;
            }
            $imageSource = $disk->path($posterPath);
        } else {
            $imageSource = $source;
        }

        if (!$disk->exists($thumbPath)) {
            GenerateThumbnail::generateImageThumbnail($imageSource, $disk->path($thumbPath));
        }
        if (!$disk->exists($thumbPath)) {
            return;
        }

        $item->update(['thumbnail_path' => $thumbPath, 'poster_path' => $posterPath]);

        if (app(MediaStore::class)->offloadEnabled()) {
            OffloadLibraryItem::dispatch($item);
        }
    }
}
