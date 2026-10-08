<?php

namespace App\Services;

use App\Models\Show;
use App\Services\Widgets\OverlayWidgets;

/**
 * A show's frame as the players receive it: an optional base image/video
 * drawn under every slide, and an optional overlay (an image plus live
 * widgets) drawn over every slide, above the slide's own overlay and
 * widgets. Returns null when the show has neither, so players pay nothing.
 *
 * Same field names as a slide's (file_url, mime_type, overlay_url,
 * overlay_widgets) so the players' existing pieces apply unchanged.
 */
class ShowFrame
{
    public function __construct(private OverlayWidgets $widgets) {}

    /**
     * For the browser player. A shared link passes its token so widget data
     * is fetched through that link's own endpoint (no login for a guest).
     */
    public function forPlayer(Show $show, ?string $playLinkToken = null): ?array
    {
        [$base, $overlay] = [$show->baseMedia, $show->overlayMedia];
        if (!$base && !$overlay) {
            return null;
        }

        $widgets = collect($this->widgets->forPlayer($overlay));
        if ($playLinkToken) {
            $widgets = $widgets->map(fn (array $w) => [...$w, 'data_url' => route('play.widget-data', [
                'token' => $playLinkToken,
                'slideMedia' => $overlay->id,
                'element' => $w['id'],
                'endpoint' => '__endpoint__',
            ])]);
        }

        return [
            'file_url'        => $base?->file_url,
            'mime_type'       => $base?->mime_type,
            'overlay_url'     => $overlay?->file_url,
            'overlay_widgets' => $widgets->values()->all(),
        ];
    }

    /** For a Slide Announcer device (no URLs for widgets — it serves bundles itself). */
    public function forDevice(Show $show): ?array
    {
        [$base, $overlay] = [$show->baseMedia, $show->overlayMedia];
        if (!$base && !$overlay) {
            return null;
        }

        return [
            'file_url'         => $base?->file_url,
            'mime_type'        => $base?->mime_type,
            'overlay_url'      => $overlay?->file_url,
            'overlay_mime_type' => $overlay?->mime_type,
            'overlay_media_id' => $overlay?->id,
            'widgets'          => $this->widgets->forDevice($overlay),
        ];
    }
}
