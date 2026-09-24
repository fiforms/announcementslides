<?php

namespace App\Jobs;

use App\Models\Slide;
use App\Services\OverlayCompositor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

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
 * The compositing itself (including SVG rasterization) lives in
 * OverlayCompositor, shared with the PowerPoint export.
 */
class SyncOverlayThumbnail implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $slideId) {}

    public function handle(OverlayCompositor $compositor): void
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
        $destRelPath = "thumbs/{$slide->id}-composite.jpg";

        if ($compositor->flatten($disk->path($primary->thumbnail_path), $overlay, $disk->path($destRelPath))) {
            $slide->update(['overlay_thumbnail_path' => $destRelPath]);
        }
    }

    private function clear(Slide $slide): void
    {
        if ($slide->overlay_thumbnail_path) {
            Storage::disk('public')->delete($slide->overlay_thumbnail_path);
            $slide->update(['overlay_thumbnail_path' => null]);
        }
    }
}
