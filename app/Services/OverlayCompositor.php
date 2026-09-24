<?php

namespace App\Services;

use App\Models\SlideMedia;
use GdImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Flattens a slide's overlay onto a base image, using the same "stack and
 * object-contain" compositing as the lightbox/slideshow: the base and the
 * overlay are each scaled to fit the canvas, centered, with the overlay's
 * alpha preserved. Used both for the small listing thumbnail
 * (SyncOverlayThumbnail) and the full-resolution PowerPoint export.
 *
 * An SVG overlay is rasterized to a temporary PNG via rsvg-convert first
 * (see rasterizeSvg()), since GD can't decode SVG itself. That binary is
 * optional — without it, SVG overlays are simply skipped (flatten() returns
 * false and callers fall back to the base alone) rather than failing;
 * PNG/WebP overlays are unaffected either way.
 */
class OverlayCompositor
{
    /**
     * Writes $overlay composited over $basePath to $destPath as a JPEG.
     * The canvas is $canvasWidth x $canvasHeight on black (the base is
     * object-contained onto it), or the base's own size when omitted.
     * Returns false (a no-op, not a fatal error) if either image can't be
     * decoded.
     */
    public function flatten(
        string $basePath,
        SlideMedia $overlay,
        string $destPath,
        ?int $canvasWidth = null,
        ?int $canvasHeight = null,
        int $quality = 85,
    ): bool {
        $overlayPath = Storage::disk('public')->path($overlay->disk_path);
        if (!file_exists($basePath) || !file_exists($overlayPath)) {
            return false;
        }

        $base = $this->decode($basePath);
        if (!$base) {
            return false;
        }

        $canvasWidth ??= imagesx($base);
        $canvasHeight ??= imagesy($base);

        $rasterizedOverlayPath = $overlay->mime_type === 'image/svg+xml'
            ? $this->rasterizeSvg($overlayPath, $canvasWidth, $canvasHeight)
            : $overlayPath;

        $overlaySrc = $rasterizedOverlayPath ? $this->decode($rasterizedOverlayPath) : null;

        if ($rasterizedOverlayPath && $rasterizedOverlayPath !== $overlayPath) {
            @unlink($rasterizedOverlayPath);
        }

        if (!$overlaySrc) {
            imagedestroy($base);
            return false;
        }

        $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 0, 0, 0));
        imagealphablending($canvas, true);

        $this->drawContained($canvas, $base);
        $this->drawContained($canvas, $overlaySrc);

        $dir = dirname($destPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        imagejpeg($canvas, $destPath, $quality);

        imagedestroy($base);
        imagedestroy($overlaySrc);
        imagedestroy($canvas);

        return file_exists($destPath);
    }

    /**
     * Rasterizes an SVG overlay to a temporary PNG via rsvg-convert, fit
     * within the target canvas so it's sharp at the output size. Returns
     * null — a graceful no-op, not a fatal error — if the binary isn't
     * installed or the conversion fails.
     */
    private function rasterizeSvg(string $svgPath, int $width, int $height): ?string
    {
        $tempPath = sys_get_temp_dir() . '/overlay-' . Str::uuid() . '.png';

        $process = new Process([
            config('slides.rsvg_binary'),
            '-w', (string) $width, '-h', (string) $height, '--keep-aspect-ratio',
            $svgPath, '-o', $tempPath,
        ]);
        $process->setTimeout(15);
        $process->run();

        if (!$process->isSuccessful() || !file_exists($tempPath)) {
            Log::warning('SVG overlay rasterization failed or rsvg-convert is not installed', [
                'svg_path' => $svgPath,
                'rsvg_binary' => config('slides.rsvg_binary'),
                'error' => trim($process->getErrorOutput()) ?: $process->getExitCodeText(),
            ]);
            @unlink($tempPath);

            return null;
        }

        return $tempPath;
    }

    private function decode(string $path): ?GdImage
    {
        $info = @getimagesize($path);
        if (!$info) {
            return null;
        }

        $image = match ($info[2]) {
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            default => false,
        };

        return $image ?: null;
    }

    /**
     * Draws $src onto $canvas object-contain style (scaled to fit, centered),
     * alpha-blended.
     */
    private function drawContained(GdImage $canvas, GdImage $src): void
    {
        $canvasWidth = imagesx($canvas);
        $canvasHeight = imagesy($canvas);
        $srcWidth = imagesx($src);
        $srcHeight = imagesy($src);

        $scale = min($canvasWidth / $srcWidth, $canvasHeight / $srcHeight);
        $destWidth = max(1, (int) round($srcWidth * $scale));
        $destHeight = max(1, (int) round($srcHeight * $scale));
        $destX = (int) round(($canvasWidth - $destWidth) / 2);
        $destY = (int) round(($canvasHeight - $destHeight) / 2);

        imagecopyresampled($canvas, $src, $destX, $destY, 0, 0, $destWidth, $destHeight, $srcWidth, $srcHeight);
    }
}
