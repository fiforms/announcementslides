<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\LiveChannel;
use App\Models\Show;
use App\Models\Slide;
use App\Services\ShowFrame;
use App\Services\Widgets\OverlayWidgets;
use App\Support\WidgetLocation;
use App\Support\SlideAnnouncerVideoReceiver;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;


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
            // The legacy kiosk build needs a file for every slide.
            ->withBase()
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
            // 'sequence' loops its slides; 'menu' is a grid to pick one from.
            'play_mode' => $show->play_mode,
            // The show's frame (background + overlay/widgets over every
            // slide), or null. With a background video, slides containing
            // videos aren't sent at all — never two videos at once.
            'frame' => app(ShowFrame::class)->forDevice($show),
            'slides' => Slide::with(['primaryMedia', 'overlayMedia', 'language'])
                ->orderedInShow($show->id)
                ->current()
                ->withContent()
                ->when($show->skipsVideoSlides(), fn ($q) => $q->withoutVideo())
                ->get()
                ->map(fn (Slide $slide) => $this->slideEntry($slide)),
        ]);

        $shows = $shows->concat($this->liveChannelShows($device->entity));

        return response()->json([
            'shows' => $shows,
            // Every widget bundle the slides above place, for the device to
            // mirror into its own cache (it never loads code off the server
            // at display time, so widgets keep working offline).
            'widgets' => app(OverlayWidgets::class)->bundlesFor(
                $shows->flatMap(fn ($show) => $show['slides']->flatMap(fn ($slide) => $slide['widgets'])
                    ->concat($show['frame']['widgets'] ?? []))->all()
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

    /**
     * The live channel catalog as extra "shows" the remote menu offers
     * (grouped under "Live Channels"): no slides, the channel as the frame,
     * ids prefixed `channel-` so they never collide with a real show's. The
     * device plays, pins and un-pins them like any other show. Only when the
     * church offers live channels (on by default).
     */
    private function liveChannelShows(?Entity $entity): Collection
    {
        if (!$entity?->offer_live_channels) {
            return collect();
        }

        return LiveChannel::orderBy('name')->get()
            ->map(fn (LiveChannel $channel) => [$channel, $channel->forPlayer(false)])
            ->filter(fn (array $pair) => $pair[1] !== null)
            ->map(fn (array $pair) => [
                'id' => 'channel-' . $pair[0]->id,
                'name' => $pair[0]->name,
                'is_main' => false,
                'is_channel' => true,
                'frame' => [
                    'file_url' => null,
                    'mime_type' => null,
                    'youtube_url' => null,
                    'channel' => $pair[1],
                    'overlay_url' => null,
                    'overlay_mime_type' => null,
                    'overlay_media_id' => null,
                    'widgets' => [],
                ],
                'slides' => collect(),
            ])
            ->values();
    }

    private function slideEntry(Slide $slide): array
    {
        return [
            'id' => $slide->id,
            'title' => $slide->title,
            // Every language is distributed to every device; the device
            // shows only slides matching its own language (or untagged
            // ones, null) — see local-app/backend/main.py's slideshow().
            'language' => $slide->language?->abbreviation,
            'file_url' => $slide->file_url,
            'thumbnail_url' => $slide->thumbnail_url,
            'mime_type' => $slide->mime_type,
            'video_muted' => $slide->video_muted,
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
