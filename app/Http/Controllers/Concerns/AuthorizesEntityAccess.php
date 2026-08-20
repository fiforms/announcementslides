<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

/**
 * Shared "the current user belongs to this entity, and is an admin of it (or
 * a site admin)" gate used by every entity-leader-scoped controller
 * (LocalSlideController, ShowController, EntitySlideAnnouncerController).
 * $entityId is read from the `entity_id` query string, matching this
 * codebase's existing convention for entity-scoped routes, falling back to
 * (in order) the last entity_id seen this session, then the user's sole
 * entity if they only lead one — so a bare bookmark/typed URL with no query
 * string doesn't just 403.
 */
trait AuthorizesEntityAccess
{
    private function authorizedEntityId(Request $request, bool $requireAdmin = true): int
    {
        $user = $request->user();
        $entityId = (int) $request->query('entity_id');

        if ($entityId) {
            session(['current_entity_id' => $entityId]);
        } else {
            $entityId = (int) session('current_entity_id');
        }

        if (!$entityId) {
            $memberIds = $user->memberEntityIds();
            if (count($memberIds) === 1) {
                $entityId = $memberIds[0];
            }
        }

        abort_unless(
            $entityId && in_array($entityId, $user->memberEntityIds()),
            403
        );

        if ($requireAdmin) {
            abort_unless($user->isAdmin() || $user->isEntityAdmin($entityId), 403);
        }

        return $entityId;
    }

    /**
     * For a GET index page reached without an explicit `entity_id` (a bare
     * bookmark, a typed URL, a stale link): once authorizedEntityId() above
     * has resolved one via fallback, redirect to the canonical
     * `?entity_id=...` URL rather than silently rendering it, so the address
     * bar stays bookmarkable/shareable and back/forward behaves.
     */
    private function redirectToEntityUrl(Request $request, string $routeName, int $entityId): ?RedirectResponse
    {
        if ($request->query('entity_id')) {
            return null;
        }

        return redirect()->route($routeName, array_merge($request->query(), ['entity_id' => $entityId]));
    }
}
