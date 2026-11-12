<?php

namespace App\Jobs;

use App\Models\SlideMedia;
use App\Services\VideoFrameExtractor;
use App\Support\MediaStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Builds a media row's 600px listing thumbnail (always on the local `public`
 * disk). For a video it first stores a full-size poster frame
 * (`poster_path`, also local), taken once with ffmpeg, and makes the
 * thumbnail from that. Rebuilding a thumbnail later, or exporting the slide
 * to PowerPoint, then never needs the video itself, which matters once the
 * video has been offloaded (see MediaStore).
 *
 * Afterwards it hands the row to OffloadMedia, which moves the file off the
 * web host if offloading is configured and the file qualifies.
 */
class GenerateThumbnail implements ShouldQueue
{
    use Queueable;

    public function __construct(public SlideMedia $media, public bool $offloadAfter = true) {}

    public function handle(VideoFrameExtractor $frames): void
    {
        $store = app(MediaStore::class);
        $media = $this->media;
        $disk  = Storage::disk(MediaStore::LOCAL);

        // SVG is an image/* type GD can't decode. It needs no thumbnail of
        // its own (it renders directly), but an SVG overlay still has to
        // refresh the slide's flattened composite.
        if ($media->mime_type === 'image/svg+xml') {
            $media->slide_id && SyncOverlayThumbnail::dispatch($media->slide_id);
            return;
        }

        $thumbRelPath  = 'thumbs/' . pathinfo($media->filename, PATHINFO_FILENAME) . '.jpg';
        $thumbFullPath = $disk->path($thumbRelPath);

        if ($media->isVideo()) {
            $poster = $this->ensurePoster($media, $frames);
            $poster && $this->generateImageThumbnail($poster, $thumbFullPath);
        } elseif ($media->isImage()) {
            $source = $media->localPath();
            if (!$source) {
                return;
            }
            $this->generateImageThumbnail($source, $thumbFullPath);
        } elseif (!$media->fileExists()) {
            return;
        }

        if (file_exists($thumbFullPath)) {
            $media->update(['thumbnail_path' => $thumbRelPath]);
            // A show's frame media has no slide composite to refresh.
            $media->slide_id && SyncOverlayThumbnail::dispatch($media->slide_id);
        }

        if ($this->offloadAfter && $store->offloadEnabled()) {
            OffloadMedia::dispatch($media);
        }
    }

    /**
     * The local path of the video's full-size poster frame, extracting it
     * (once) if it isn't stored yet. Null if the video can't be read.
     */
    public function ensurePoster(SlideMedia $media, VideoFrameExtractor $frames): ?string
    {
        if ($existing = $media->posterPath()) {
            return $existing;
        }

        $source = $media->localPath();
        if (!$source) {
            return null;
        }

        $posterRelPath = 'posters/' . pathinfo($media->filename, PATHINFO_FILENAME) . '.jpg';
        $posterFull    = Storage::disk(MediaStore::LOCAL)->path($posterRelPath);

        if (!$frames->extract($source, $posterFull, null, ['slide_media_id' => $media->id])) {
            return null;
        }

        $media->update(['poster_path' => $posterRelPath]);

        return $posterFull;
    }

    private function generateImageThumbnail(string $source, string $dest): void
    {
        $info = @getimagesize($source);
        if (! $info || ! $info[0] || ! $info[1]) {
            return; // not a decodable raster image — leave it without a thumbnail
        }
        [$origWidth, $origHeight, $type] = $info;

        $thumbWidth  = 600;
        $thumbHeight = (int) ($origHeight * $thumbWidth / $origWidth);

        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG  => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
            default        => null,
        };

        if (! $src) {
            return;
        }

        $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $origWidth, $origHeight);

        $dir = dirname($dest);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        imagejpeg($thumb, $dest, 85);
        imagedestroy($thumb);
        imagedestroy($src);
    }
}
