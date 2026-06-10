<?php

namespace App\Jobs;

use App\Models\Slide;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class GenerateThumbnail implements ShouldQueue
{
    use Queueable;

    public function __construct(public Slide $slide) {}

    public function handle(): void
    {
        $sourcePath = Storage::path($this->slide->disk_path);

        if (! file_exists($sourcePath)) {
            return;
        }

        $ext = pathinfo($this->slide->filename, PATHINFO_EXTENSION);
        $thumbRelPath = 'thumbs/' . pathinfo($this->slide->filename, PATHINFO_FILENAME) . '.jpg';
        $thumbFullPath = Storage::path($thumbRelPath);

        if ($this->slide->isImage()) {
            $this->generateImageThumbnail($sourcePath, $thumbFullPath);
        }
        // Video thumbnail via ffmpeg can be added here later

        if (file_exists($thumbFullPath)) {
            $this->slide->update(['thumbnail_path' => $thumbRelPath]);
        }
    }

    private function generateImageThumbnail(string $source, string $dest): void
    {
        [$origWidth, $origHeight, $type] = getimagesize($source);

        $thumbWidth  = 600;
        $thumbHeight = (int) ($origHeight * $thumbWidth / $origWidth);

        $src = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($source),
            IMAGETYPE_PNG  => imagecreatefrompng($source),
            IMAGETYPE_WEBP => imagecreatefromwebp($source),
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
