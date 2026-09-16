<?php

namespace App\Policies;

use App\Models\Entity;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The two shapes of "may this user act on this slide", previously hand-copied
 * as abort_unless() triples across LocalSlideController, EntitySlideController
 * and (as a private helper) MySlideController.
 *
 * Those copies were all correct. The reason they are worth consolidating is
 * that a missing one looks identical to a present one from inside any single
 * file — which is how the public SlideController came to omit the visibility
 * scope entirely (#2) without anything flagging it.
 *
 * The 403/404 split is deliberate and preserved exactly. A caller who may not
 * act on a slide gets 403; a slide belonging to a *different* entity gets 404,
 * so an entity-scoped screen never confirms that another church's slide
 * exists. Check order matters for the same reason and is kept as-is: the
 * permission checks run before the entity-match check, so a stranger gets 403
 * rather than learning the slide is elsewhere.
 */
class SlidePolicy
{
    /**
     * Manage a slide through an entity-scoped screen: the entity's own leaders
     * and site admins, acting on their own uploads, within that entity.
     */
    public function manageForEntity(User $user, Slide $slide, Entity|int $entity): Response
    {
        $entityId = $entity instanceof Entity ? $entity->id : (int) $entity;

        if (! $user->isAdmin() && ! $user->isEntityAdmin($entityId)) {
            return Response::deny();
        }

        if (! $user->isAdmin() && $slide->uploaded_by !== $user->id) {
            return Response::deny();
        }

        if ($slide->entity_id !== $entityId) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    /**
     * Reach an entity's slide screens at all, without a specific slide in hand
     * — the index listing.
     */
    public function manageAnyForEntity(User $user, Entity|int $entity): Response
    {
        $entityId = $entity instanceof Entity ? $entity->id : (int) $entity;

        return $user->isAdmin() || $user->isEntityAdmin($entityId)
            ? Response::allow()
            : Response::deny();
    }

    /**
     * Manage one's own unscoped (global) slide through /my-slides.
     *
     * Ownership alone is not enough: a user demoted to "viewer" may still own
     * global slides from when they were a contributor, but must not be able to
     * modify them. Editing/archiving requires current contributor permissions.
     */
    public function manageUnscoped(User $user, Slide $slide): Response
    {
        return $user->isContributor()
            && $slide->uploaded_by === $user->id
            && $slide->entity_id === null
                ? Response::allow()
                : Response::deny();
    }
}
