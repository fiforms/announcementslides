<?php

namespace App\Jobs;

use App\Models\Slide;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Keeps Slide::overlay_thumbnail_path in sync with the slide's current
 * primary + overlay media: when both exist, flattens the overlay on top of
 * the primary's thumbnail (same "stack and object-contain" compositing as
 * the lightbox/slideshow) into one JPEG, so every place that just renders
 * Slide::thumbnail_url (cards, rows, listings) shows the combined image
 * without needing to know overlays exist at all. Clears it back to null
 * (falling back to the primary's own thumbnail) when there's no overlay.
 *
 * Dispatched from GenerateThumbnail (after a primary or overlay media's own
 * thumbnail is (re)generated) and from ManagesSlideMedia::destroyMediaForSlide
 * (media removal can also change which composite, if any, is correct) — each
 * run recomputes from the slide's current state, so dispatch order/races
 * between the two media rows self-heal on whichever run happens last.
 *
 * An SVG overlay is rasterized to a temporary PNG via rsvg-convert first
 * (see rasterizeSvg()), since GD can't decode SVG itself. That binary is
 * optional — without it, SVG overlays are simply skipped (the composite step
 * no-ops and the thumbnail keeps falling back to the primary alone) rather
 * than failing; PNG/WebP overlays are unaffected either way.
 */
class SyncOverlayThumbnail implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $slideId) {}

    public function handle(): void
    {
        $slide = Slide::with(['primaryMedia', 'overlayMedia'])->find($this->slideId);
        if (!$slide) {
            return;
        }

        $primary = $slide->primaryMedia;
        $overlay = $slide->overlayMedia;

        if (!$primary?->thumbnail_path || !$overlay) {
            $this->clear($slide);
            return;
        }

        $disk = Storage::disk('public');
        $basePath = $disk->path($primary->thumbnail_path);
        $overlayPath = $disk->path($overlay->disk_path);

        if (!file_exists($basePath) || !file_exists($overlayPath)) {
            return;
        }

        $rasterizedOverlayPath = $overlay->mime_type === 'image/svg+xml'
            ? $this->rasterizeSvg($overlayPath)
            : $overlayPath;

        if (!$rasterizedOverlayPath) {
            return;
        }

        $destRelPath = "thumbs/{$slide->id}-composite.jpg";
        $destPath = $disk->path($destRelPath);

        if ($this->composite($basePath, $rasterizedOverlayPath, $destPath)) {
            $slide->update(['overlay_thumbnail_path' => $destRelPath]);
        }

        if ($rasterizedOverlayPath !== $overlayPath) {
            @unlink($rasterizedOverlayPath);
        }
    }

    /**
     * Rasterizes an SVG overlay to a temporary PNG via rsvg-convert, since GD
     * can't decode SVG itself. Returns null — a graceful no-op, not a fatal
     * error — if the binary isn't installed or the conversion fails; the
     * slide's thumbnail then just keeps falling back to the primary alone.
     */
    private function rasterizeSvg(string $svgPath): ?string
    {
        $tempPath = sys_get_temp_dir() . '/overlay-' . Str::uuid() . '.png';

        $process = new Process([config('slides.rsvg_binary'), '-w', '600', $svgPath, '-o', $tempPath]);
        $process->setTimeout(15);
        $process->run();

        if (!$process->isSuccessful() || !file_exists($tempPath)) {
            Log::warning('SVG overlay rasterization failed or rsvg-convert is not installed', [
                'svg_path' => $svgPath,
                'rsvg_binary' => config('slides.rsvg_binary'),
                'error' => trim($process->getErrorOutput()) ?: $process->getExitCodeText(),
            ]);

            return null;
        }

        return $tempPath;
    }

    private function clear(Slide $slide): void
    {
        if ($slide->overlay_thumbnail_path) {
            Storage::disk('public')->delete($slide->overlay_thumbnail_path);
            $slide->update(['overlay_thumbnail_path' => null]);
        }
    }

    /**
     * Composite $overlayPath (object-contain, centered, alpha-preserved)
     * onto $basePath, writing the flattened result to $destPath. $overlayPath
     * must already be a raster format GD can decode (PNG/WebP/JPEG) — SVGs
     * are rasterized by rasterizeSvg() before reaching this method. Returns
     * false (a no-op, not a fatal error) if it turns out not to be decodable.
     */
    private function composite(string $basePath, string $overlayPath, string $destPath): bool
    {
        $base = @imagecreatefromjpeg($basePath);
        if (!$base) {
            return false;
        }

        $overlayInfo = @getimagesize($overlayPath);
        if (!$overlayInfo) {
            imagedestroy($base);
            return false;
        }
        [$overlayWidth, $overlayHeight, $overlayType] = $overlayInfo;

        $overlaySrc = match ($overlayType) {
            IMAGETYPE_PNG => @imagecreatefrompng($overlayPath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($overlayPath),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($overlayPath),
            default => null,
        };
        if (!$overlaySrc) {
            imagedestroy($base);
            return false;
        }

        $baseWidth = imagesx($base);
        $baseHeight = imagesy($base);

        $scale = min($baseWidth / $overlayWidth, $baseHeight / $overlayHeight);
        $destWidth = max(1, (int) round($overlayWidth * $scale));
        $destHeight = max(1, (int) round($overlayHeight * $scale));
        $destX = (int) round(($baseWidth - $destWidth) / 2);
        $destY = (int) round(($baseHeight - $destHeight) / 2);

        $resized = imagecreatetruecolor($destWidth, $destHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefilledrectangle($resized, 0, 0, $destWidth, $destHeight, $transparent);
        imagecopyresampled($resized, $overlaySrc, 0, 0, 0, 0, $destWidth, $destHeight, $overlayWidth, $overlayHeight);

        imagealphablending($base, true);
        imagecopy($base, $resized, $destX, $destY, 0, 0, $destWidth, $destHeight);

        $dir = dirname($destPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        imagejpeg($base, $destPath, 85);

        imagedestroy($base);
        imagedestroy($overlaySrc);
        imagedestroy($resized);

        return file_exists($destPath);
    }
}
