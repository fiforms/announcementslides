<?php

namespace App\Http\Controllers;

use App\Models\PlayLink;
use App\Models\Slide;
use App\Models\Widget;
use App\Services\ShowFrame;
use App\Support\WidgetLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Public, login-free playback of a PlayLink (/play/{token}). Everything the
 * viewer may see is derived from the link — never from the query string — and
 * only the fields the player needs are exposed.
 */
class PlayController extends Controller
{
    /** How often (seconds) a playing page re-checks for changed slides. */
    private const REFRESH_SECONDS = 300;

    public function show(Request $request, string $token)
    {
        $link = $this->activeLink($token);

        $response = Inertia::render('Play/Show', [
            ...$this->payload($link),
            'token' => $link->token,
            'refreshSeconds' => self::REFRESH_SECONDS,
            'widgetLocation' => fn () => Widget::enabledBySlug() ? WidgetLocation::for($link->entity) : null,
        ])->toResponse($request);

        return $this->private($response);
    }

    /** Polled by the open player so edits and revocation reach it without a reload. */
    public function slides(string $token): JsonResponse
    {
        return $this->private(response()->json($this->payload($this->activeLink($token))));
    }

    private function activeLink(string $token): PlayLink
    {
        $link = PlayLink::active()->where('token', $token)->with('entity')->first();
        abort_unless($link, 404);

        if (! $link->last_used_at || $link->last_used_at->lt(now()->subMinute())) {
            $link->toBase()->where('id', $link->id)->update(['last_used_at' => now()]);
        }

        return $link;
    }

    private function payload(PlayLink $link): array
    {
        $show = $link->resolvedShow();
        $slides = Slide::with(['primaryMedia', 'overlayMedia'])
            ->orderedInShow($show->id)
            ->current()
            ->language($link->language_id)
            // A background video plays alone: slides with videos are skipped.
            ->when($show->skipsVideoSlides(), fn ($q) => $q->withoutVideo())
            ->get()
            ->map(fn (Slide $slide) => $this->slideResource($slide, $link))
            ->values();

        return [
            'title' => $link->title,
            'delaySeconds' => $link->delay_seconds,
            'slides' => $slides,
            'frame' => app(ShowFrame::class)->forPlayer($show, $link->token),
        ];
    }

    private function slideResource(Slide $slide, PlayLink $link): array
    {
        // Widget data on entity-local slides isn't readable by a guest, so
        // the player fetches it through this link's own endpoint instead.
        $widgets = collect($slide->overlay_widgets)->map(fn (array $w) => [
            ...$w,
            'data_url' => route('play.widget-data', [
                'token' => $link->token,
                'slideMedia' => $slide->overlayMedia->id,
                'element' => $w['id'],
                'endpoint' => '__endpoint__',
            ]),
        ])->all();

        return [
            'id' => $slide->id,
            'title' => $slide->title,
            'mime_type' => $slide->mime_type,
            'video_playback_mode' => $slide->video_playback_mode,
            'file_url' => $slide->file_url,
            'overlay_url' => $slide->overlay_url,
            'overlay_widgets' => $widgets,
        ];
    }

    /** The URL is a secret: keep it out of Referer headers, caches and search indexes. */
    private function private($response)
    {
        return $response->withHeaders([
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
