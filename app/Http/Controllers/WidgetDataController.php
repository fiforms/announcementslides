<?php

namespace App\Http\Controllers;

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

        return $this->respond($widget, $endpoint, $placement['params'] ?? [], $this->callerKey($request));
    }

    public function preview(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->adminEntities()->exists(), 403);

        $request->validate([
            'widget'   => 'required|string|max:64',
            'endpoint' => 'required|string|max:64',
            'params'   => 'nullable|array',
        ]);
        $widget = Widget::where('slug', $request->input('widget'))->where('enabled', true)->first();
        abort_unless($widget, 404);

        try {
            $params = WidgetParams::clean($widget, $request->input('params', []));
        } catch (WidgetPackageException $e) {
            return $this->error('invalid_params', 422, $e->errors);
        }

        return $this->respond($widget, $request->input('endpoint'), $params, 'preview:' . $user->id);
    }

    private function respond(Widget $widget, string $endpoint, array $params, string $callerKey): JsonResponse
    {
        try {
            $result = $this->data->get($widget, $endpoint, $params, $callerKey);
        } catch (WidgetFetchException $e) {
            $status = match ($e->reason) {
                'unknown_endpoint' => 404,
                'not_configured'   => 422,
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
