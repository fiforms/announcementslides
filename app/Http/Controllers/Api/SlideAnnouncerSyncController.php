<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Show;
use App\Models\Slide;
use App\Services\Widgets\OverlayWidgets;
use App\Support\WidgetLocation;
use App\Support\SlideAnnouncerVideoReceiver;
use Illuminate\Http\Request;


class SlideAnnouncerSyncController extends Controller
{
    /**
     * Legacy flat full sync every poll, no incremental cursoring — see
     * SLIDE_ANNOUNCER.md, "Sync endpoint." Kept working unchanged (mapped to
     * the entity's Main show, which already contains every global slide via
     * the show_slides fan-out) so already-deployed kiosks don't break while
     * the new multi-show kiosk build (consuming shows() below) rolls out.
     */
    public function index(Request $request)
    {
        $device = $request->user();
        $mainShow = $device->entity->mainShow();

        $slides = Slide::with(['primaryMedia', 'overlayMedia'])
            ->orderedInShow($mainShow->id)
            ->current()
            ->get()
            ->map(fn (Slide $slide) => $this->slideEntry($slide));

        return response()->json([
            'settings' => $device->settings ?? [],
            'slides' => $slides,
        ]);
    }

    /**
     * Every show belonging to the device's entity, each fully expanded with
     * its own ordered slide list — lets the kiosk sync all shows and let the
     * user pick one locally (see the slideannouncer submodule's Menu
     * overlay), rather than being assigned a single show server-side.
     */
    public function shows(Request $request)
    {
        $device = $request->user();
        $shows = Show::where('entity_id', $device->entity_id)->get();

        $shows = $shows->map(fn (Show $show) => [
            'id' => (string) $show->id,
            'name' => $show->name,
            'is_main' => $show->is_main,
            'slides' => Slide::with(['primaryMedia', 'overlayMedia', 'language'])
                ->orderedInShow($show->id)
                ->current()
                ->get()
                ->map(fn (Slide $slide) => $this->slideEntry($slide)),
        ]);

        return response()->json([
            'shows' => $shows,
            // Every widget bundle the slides above place, for the device to
            // mirror into its own cache (it never loads code off the server
            // at display time, so widgets keep working offline).
            'widgets' => app(OverlayWidgets::class)->bundlesFor(
                $shows->flatMap(fn ($show) => $show['slides']->flatMap(fn ($slide) => $slide['widgets']))->all()
            ),
            // This screen's location for widgets' api.location — its
            // church's, else the site default (App\Support\WidgetLocation).
            'location' => WidgetLocation::for($device->entity),
            'settings' => $device->settings ?? [],
            // Same push as the heartbeat response's — carried here too so a
            // web edit reaches the device within one sync (~60s) rather
            // than one heartbeat (5 min). See App\Support\SlideAnnouncerVideoReceiver.
            'srt_sink_config' => SlideAnnouncerVideoReceiver::push($device),
        ]);
    }

    private function slideEntry(Slide $slide): array
    {
        return [
            'id' => $slide->id,
            // Every language is distributed to every device; the device
            // shows only slides matching its own language (or untagged
            // ones, null) — see local-app/backend/main.py's slideshow().
            'language' => $slide->language?->abbreviation,
            'file_url' => $slide->file_url,
            'thumbnail_url' => $slide->thumbnail_url,
            'mime_type' => $slide->mime_type,
            'video_playback_mode' => $slide->video_playback_mode,
            'overlay_url' => $slide->overlay_url,
            'overlay_mime_type' => $slide->overlay_mime_type,
            // Live widgets on the overlay — see App\Services\Widgets. The
            // overlay's id is what the device's widget-data requests name.
            'overlay_media_id' => $slide->overlayMedia?->id,
            'widgets' => app(OverlayWidgets::class)->forDevice($slide->overlayMedia),
            'expires_at' => $slide->expires_at?->toIso8601String(),
        ];
    }
}
