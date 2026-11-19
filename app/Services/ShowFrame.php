<?php

namespace App\Services;

use App\Models\Show;
use App\Support\YoutubeSource;
use App\Services\Widgets\OverlayWidgets;

/**
 * A show's frame as the players receive it: an optional base image/video
 * drawn under every slide, and an optional overlay (an image plus live
 * widgets) drawn over every slide, above the slide's own overlay and
 * widgets. Returns null when the show has neither, so players pay nothing.
 *
 * The background may instead be a YouTube video (`youtube_url`) or a live
 * channel (`channel`: {kind: 'iframe'|'hls', url, muted}, see LiveChannel).
 *
 * Background music is `audio` ({tracks: [{id, url, mime_type, title}], volume
 * 0-100}), played in order and looped by the player. It is independent of
 * the background, so a show may have only music.
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
        $youtube = $this->youtubeUrl($show);
        $channel = $this->channel($show);
        $audio = $this->audio($show);
        if (!$base && !$overlay && !$youtube && !$channel && !$audio) {
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
            'youtube_url'     => $youtube,
            'channel'         => $channel,
            'audio'           => $audio,
            'overlay_url'     => $overlay?->file_url,
            'overlay_widgets' => $widgets->values()->all(),
        ];
    }

    /** The embed URL of a YouTube background, built from the stored ids, or null. */
    private function youtubeUrl(Show $show): ?string
    {
        $stored = $show->frame_youtube;

        return $stored ? YoutubeSource::embedUrl($stored, (bool) ($stored['muted'] ?? false)) : null;
    }

    /** The live channel background as players render it, or null. */
    private function channel(Show $show): ?array
    {
        return $show->frame_channel_id ? $show->liveChannel?->forPlayer($show->frame_channel_muted) : null;
    }

    /** The background music as players receive it, or null. */
    private function audio(Show $show): ?array
    {
        $tracks = $show->audioItems->filter(fn ($i) => $i->file_url)->map(fn ($i) => [
            'id' => $i->id, 'url' => $i->file_url, 'mime_type' => $i->mime_type, 'title' => $i->title,
        ])->values()->all();

        return $tracks ? ['tracks' => $tracks, 'volume' => (int) $show->frame_audio_volume] : null;
    }

    /** For a Slide Announcer device (no URLs for widgets — it serves bundles itself). */
    public function forDevice(Show $show): ?array
    {
        [$base, $overlay] = [$show->baseMedia, $show->overlayMedia];
        $youtube = $this->youtubeUrl($show);
        $channel = $this->channel($show);
        $audio = $this->audio($show);
        if (!$base && !$overlay && !$youtube && !$channel && !$audio) {
            return null;
        }

        return [
            'file_url'         => $base?->file_url,
            'mime_type'        => $base?->mime_type,
            'youtube_url'      => $youtube,
            'channel'          => $channel,
            'audio'            => $audio,
            'overlay_url'      => $overlay?->file_url,
            'overlay_mime_type' => $overlay?->mime_type,
            'overlay_media_id' => $overlay?->id,
            'widgets'          => $this->widgets->forDevice($overlay),
        ];
    }
}
