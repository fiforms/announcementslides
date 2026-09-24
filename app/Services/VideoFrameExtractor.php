<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Grabs a single still frame from a video with ffmpeg — for the listing
 * thumbnail (GenerateThumbnail) and for video slides in the PowerPoint
 * export. The frame is taken 1s in (skipping a possible black opening
 * frame); clips shorter than that fall back to the very first frame.
 */
class VideoFrameExtractor
{
    /**
     * Writes the frame to $dest (format from its extension), scaled to
     * $width with the aspect ratio kept, or at the video's own size when
     * $width is null. Returns false, logging why, if ffmpeg is missing or
     * can't decode the video.
     */
    public function extract(string $source, string $dest, ?int $width = null, array $logContext = []): bool
    {
        $dir = dirname($dest);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $error = $this->run($source, $dest, 1, $width);
        if ($error !== null) {
            $error = $this->run($source, $dest, 0, $width);
        }

        if ($error !== null) {
            @unlink($dest);
            Log::warning('Video frame extraction failed', $logContext + [
                'source' => $source,
                'ffmpeg_binary' => config('slides.ffmpeg_binary'),
                'error' => $error,
            ]);

            return false;
        }

        return true;
    }

    /**
     * @return ?string null on success, otherwise the error output
     */
    private function run(string $source, string $dest, int $seekSeconds, ?int $width): ?string
    {
        $process = new Process([
            config('slides.ffmpeg_binary'), '-y',
            '-ss', (string) $seekSeconds,
            '-i', $source,
            '-vframes', '1',
            ...($width ? ['-vf', "scale={$width}:-2"] : []),
            '-q:v', '2',
            $dest,
        ]);
        $process->setTimeout(30);
        $process->run();

        if (file_exists($dest) && filesize($dest) > 0) {
            return null;
        }

        return trim($process->getErrorOutput()) ?: $process->getExitCodeText();
    }
}
