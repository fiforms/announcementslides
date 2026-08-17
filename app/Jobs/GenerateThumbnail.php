<?php

namespace App\Jobs;

use App\Models\SlideMedia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class GenerateThumbnail implements ShouldQueue
{
    use Queueable;

    public function __construct(public SlideMedia $media) {}

    public function handle(): void
    {
        $disk = Storage::disk('public');
        $sourcePath = $disk->path($this->media->disk_path);

        if (! file_exists($sourcePath)) {
            return;
        }

        $thumbRelPath = 'thumbs/' . pathinfo($this->media->filename, PATHINFO_FILENAME) . '.jpg';
        $thumbFullPath = $disk->path($thumbRelPath);

        if ($this->media->isImage()) {
            $this->generateImageThumbnail($sourcePath, $thumbFullPath);
        } elseif ($this->media->isVideo()) {
            $this->generateVideoThumbnail($sourcePath, $thumbFullPath);
        }

        if (file_exists($thumbFullPath)) {
            $this->media->update(['thumbnail_path' => $thumbRelPath]);
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

    private function generateVideoThumbnail(string $source, string $dest): void
    {
        $dir = dirname($dest);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Grab a frame 1s in (skips a possible black opening frame); short
        // clips under 1s fall back to the very first frame.
        if (! $this->extractFrame($source, $dest, 1)) {
            $this->extractFrame($source, $dest, 0);
        }
    }

    private function extractFrame(string $source, string $dest, int $seekSeconds): bool
    {
        $process = new Process([
            'ffmpeg', '-y',
            '-ss', (string) $seekSeconds,
            '-i', $source,
            '-vframes', '1',
            '-vf', 'scale=600:-2',
            $dest,
        ]);
        $process->setTimeout(30);
        $process->run();

        return file_exists($dest);
    }
}
