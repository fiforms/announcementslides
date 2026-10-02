<?php

namespace App\Http\Controllers;

use App\Models\PlayLink;
use App\Models\Slide;
use App\Models\SlideMedia;
use App\Models\User;
use App\Models\Widget;
use App\Services\Widgets\OverlayWidgets;
use App\Services\Widgets\WidgetDataService;
use App\Services\Widgets\WidgetFetchException;
use App\Services\Widgets\WidgetPackageException;
use App\Services\Widgets\WidgetParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Data for widgets, fetched server-side. The browser never sends a URL:
 *
 * - show(): by reference to a saved placement (overlay + element id). The
 *   URL is built from that placement's validated parameters, and access
 *   follows the slide's own visibility.
 * - preview(): for the overlay editor's live preview of unsaved changes —
 *   only for people who can edit overlays, tightly rate limited, and run
 *   through exactly the same parameter validation as a save.
 *
 * Responses are always our own JSON (never upstream bytes or content
 * types), with nosniff and a sandboxing CSP, so pointing a parameter at an
 * HTML page can't turn into script running on this origin.
 */
class WidgetDataController extends Controller
{
    public function __construct(private WidgetDataService $data) {}

    public function show(Request $request, SlideMedia $slideMedia, string $element, string $endpoint): JsonResponse
    {
        abort_unless($slideMedia->media_type === 'slide-overlay', 404);
        $slide = $slideMedia->slide;
        abort_unless($slide && $this->canView($request->user(), $slide), 404);

        $placement = OverlayWidgets::placement($slideMedia, $element);
        $widget = $placement ? Widget::where('slug', $placement['widget'])->where('enabled', true)->first() : null;
        abort_unless($widget, 404);

        return $this->respond($widget, $endpoint, $placement['params'] ?? [], $this->callerKey($request), $this->args($request->query('args')));
    }

    /**
     * The Slide Announcer device's equivalent of show(), authenticated by
     * its device token (the device's local backend proxies the kiosk's
     * requests here). A device may read a placement only on a live slide
     * in one of its own entity's shows — the same set it syncs.
     */
    public function device(Request $request, SlideMedia $slideMedia, string $element, string $endpoint): JsonResponse
    {
        $device = $request->user();
        abort_unless($slideMedia->media_type === 'slide-overlay', 404);
        $synced = Slide::current()->whereKey($slideMedia->slide_id)
            ->whereHas('shows', fn ($q) => $q->where('entity_id', $device->entity_id))
            ->exists();
        abort_unless($synced, 404);

        $placement = OverlayWidgets::placement($slideMedia, $element);
        $widget = $placement ? Widget::where('slug', $placement['widget'])->where('enabled', true)->first() : null;
        abort_unless($widget, 404);

        return $this->respond($widget, $endpoint, $placement['params'] ?? [], 'device:' . $device->id, $this->args($request->query('args')));
    }

    /**
     * The shared-link player's equivalent of show(): authorized by a PlayLink
     * token rather than a session. Only placements on a live slide in the
     * link's own show are readable.
     */
    public function playLink(Request $request, string $token, SlideMedia $slideMedia, string $element, string $endpoint): JsonResponse
    {
        $link = PlayLink::active()->where('token', $token)->first();
        abort_unless($link && $slideMedia->media_type === 'slide-overlay', 404);
        $inShow = Slide::current()->whereKey($slideMedia->slide_id)
            ->whereHas('shows', fn ($q) => $q->whereKey($link->resolvedShow()->id))
            ->exists();
        abort_unless($inShow, 404);

        $placement = OverlayWidgets::placement($slideMedia, $element);
        $widget = $placement ? Widget::where('slug', $placement['widget'])->where('enabled', true)->first() : null;
        abort_unless($widget, 404);

        return $this->respond($widget, $endpoint, $placement['params'] ?? [], 'play-link:' . $link->id, $this->args($request->query('args')));
    }

    public function preview(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->adminEntities()->exists(), 403);

        $request->validate([
            'widget'   => 'required|string|max:64',
            'endpoint' => 'required|string|max:64',
            'params'   => 'nullable|array',
            'args'     => 'nullable|array',
        ]);
        $widget = Widget::where('slug', $request->input('widget'))->where('enabled', true)->first();
        abort_unless($widget, 404);

        try {
            $params = WidgetParams::clean($widget, $request->input('params', []));
        } catch (WidgetPackageException $e) {
            return $this->error('invalid_params', 422, $e->errors);
        }

        return $this->respond($widget, $request->input('endpoint'), $params, 'preview:' . $user->id, $this->args($request->input('args')));
    }

    /**
     * Runtime args as sent by widget code (`?args[lat]=…`): scalars only and
     * a handful of them; WidgetDataService checks them against the
     * endpoint's declarations.
     */
    private function args(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->filter(fn ($v, $k) => is_string($k) && (is_scalar($v)) && mb_strlen((string) $v) <= 200)
            ->take(10)
            ->all();
    }

    private function respond(Widget $widget, string $endpoint, array $params, string $callerKey, array $args = []): JsonResponse
    {
        try {
            $result = $this->data->get($widget, $endpoint, $params, $callerKey, $args);
        } catch (WidgetFetchException $e) {
            $status = match ($e->reason) {
                'unknown_endpoint' => 404,
                'not_configured', 'invalid_args' => 422,
                'rate_limited'     => 429,
                default            => 502,
            };
            return $this->error($e->reason, $status);
        }

        return $this->secure(response()->json($result))
            ->header('Cache-Control', 'private, max-age=60');
    }

    private function error(string $reason, int $status, array $details = []): JsonResponse
    {
        return $this->secure(response()->json(array_filter(['error' => $reason, 'details' => $details]), $status))
            ->header('Cache-Control', 'no-store');
    }

    private function secure(JsonResponse $response): JsonResponse
    {
        return $response
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "sandbox; default-src 'none'");
    }

    /**
     * Who may read a placement's data: anyone who can see the slide live
     * (guests included, for public slides), plus the people who can see it
     * before it's live — site admins, its uploader, members of its entity.
     */
    private function canView(?User $user, Slide $slide): bool
    {
        if ($user?->isAdmin()) {
            return true;
        }
        if (Slide::current()->visibleToUser($user)->whereKey($slide->id)->exists()) {
            return true;
        }

        return $user && ($slide->uploaded_by === $user->id
            || ($slide->entity_id && in_array($slide->entity_id, $user->memberEntityIds())));
    }

    private function callerKey(Request $request): string
    {
        return $request->user() ? 'user:' . $request->user()->id : 'ip:' . $request->ip();
    }
}
