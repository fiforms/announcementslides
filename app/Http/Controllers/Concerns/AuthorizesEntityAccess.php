<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Shared "the current user belongs to this entity, and is an admin of it (or
 * a site admin)" gate used by every entity-leader-scoped controller
 * (LocalSlideController, ShowController). $entityId is read from the
 * `entity_id` query string, matching this codebase's existing convention for
 * entity-scoped routes.
 */
trait AuthorizesEntityAccess
{
    private function authorizedEntityId(Request $request, bool $requireAdmin = true): int
    {
        $user = $request->user();
        $entityId = (int) $request->query('entity_id');

        abort_unless(
            $entityId && in_array($entityId, $user->memberEntityIds()),
            403
        );

        if ($requireAdmin) {
            abort_unless($user->isAdmin() || $user->isEntityAdmin($entityId), 403);
        }

        return $entityId;
    }
}
