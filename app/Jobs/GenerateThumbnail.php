<?php

namespace App\Jobs;

use App\Models\SlideMedia;
use App\Services\VideoFrameExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class GenerateThumbnail implements ShouldQueue
{
    use Queueable;

    public function __construct(public SlideMedia $media) {}

    public function handle(VideoFrameExtractor $frames): void
    {
        $disk = Storage::disk('public');
        $sourcePath = $disk->path($this->media->disk_path);

        if (! file_exists($sourcePath)) {
            return;
        }

        $thumbRelPath = 'thumbs/' . pathinfo($this->media->filename, PATHINFO_FILENAME) . '.jpg';
        $thumbFullPath = $disk->path($thumbRelPath);

        // SVG is an image/* type GD can't decode. It needs no thumbnail of
        // its own (it renders directly), but an SVG overlay still has to
        // refresh the slide's flattened composite.
        if ($this->media->mime_type === 'image/svg+xml') {
            SyncOverlayThumbnail::dispatch($this->media->slide_id);
            return;
        }

        if ($this->media->isImage()) {
            $this->generateImageThumbnail($sourcePath, $thumbFullPath);
        } elseif ($this->media->isVideo()) {
            $frames->extract($sourcePath, $thumbFullPath, 600, ['slide_media_id' => $this->media->id]);
        }

        if (file_exists($thumbFullPath)) {
            $this->media->update(['thumbnail_path' => $thumbRelPath]);
            SyncOverlayThumbnail::dispatch($this->media->slide_id);
        }
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
