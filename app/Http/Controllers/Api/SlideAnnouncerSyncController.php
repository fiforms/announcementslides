<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Slide;
use Illuminate\Http\Request;

class SlideAnnouncerSyncController extends Controller
{
    /**
     * Flat full sync every poll, no incremental cursoring — see
     * SLIDE_ANNOUNCER.md, "Sync endpoint." Devices see global + local
     * slides mixed together, exactly like the web slideshow does for a
     * member of that entity.
     */
    public function index(Request $request)
    {
        $device = $request->user();

        $slides = Slide::with(['primaryMedia', 'overlayMedia'])
            ->where(fn ($q) => $q->whereNull('entity_id')->orWhere('entity_id', $device->entity_id))
            ->current()
            ->language($device->language_id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Slide $slide) => [
                'id' => $slide->id,
                'file_url' => $slide->file_url,
                'thumbnail_url' => $slide->thumbnail_url,
                'mime_type' => $slide->mime_type,
                'video_playback_mode' => $slide->video_playback_mode,
                'overlay_url' => $slide->overlay_url,
                'overlay_mime_type' => $slide->overlay_mime_type,
                'sort_order' => $slide->sort_order,
                'expires_at' => $slide->expires_at?->toIso8601String(),
            ]);

        return response()->json([
            'settings' => $device->settings ?? [],
            'slides' => $slides,
        ]);
    }
}
