<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\EntityInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An entity leader's view of everyone attached to their church, and the one
 * people-management power leaders have: adding and revoking *editors*.
 * Leaders themselves are only ever changed by a site admin (Admin → Users,
 * the directory import or `entity:assign`), and editors have no access here.
 */
class EntityPeopleController extends Controller
{
    public function index(Request $request, Entity $entity): Response
    {
        $this->authorizeLeader($request, $entity);

        $people = $entity->users()
            ->orderBy('users.name')
            ->get()
            ->map(fn (User $u) => [
                'id'    => $u->id,
                'name'  => $u->name,
                'email' => $u->email,
                'role'  => $u->pivot->role,
            ]);

        $order = ['admin' => 0, 'editor' => 1, 'viewer' => 2];

        return Inertia::render('Entity/People', [
            'entity'      => ['id' => $entity->id, 'name' => $entity->name],
            'people'      => $people->sortBy(fn ($p) => $order[$p['role']] ?? 3)->values(),
            'invitations' => $entity->invitations()->orderBy('email')->get(['id', 'email', 'created_at']),
        ]);
    }

    /** Make someone an editor: attach an existing user, or invite an email with no account yet. */
    public function store(Request $request, Entity $entity): RedirectResponse
    {
        $this->authorizeLeader($request, $entity);

        $email = strtolower($request->validate(['email' => ['required', 'email', 'max:255']])['email']);

        $user = User::whereRaw('lower(email) = ?', [$email])->first();

        if (! $user) {
            EntityInvitation::firstOrCreate(
                ['email' => $email, 'entity_id' => $entity->id],
                ['role' => 'editor', 'invited_by' => $request->user()->id],
            );

            return back()->with('success', "Invitation saved. {$email} becomes an editor when they sign in with that address.");
        }

        if ($user->role === 'banned') {
            return back()->with('error', 'That account is not able to join.');
        }

        // Never touches a leader (or an existing editor): only viewers and strangers change.
        $current = $user->entities()->where('entities.id', $entity->id)->first()?->pivot->role;

        if (in_array($current, User::MANAGER_ENTITY_ROLES, true)) {
            return back()->with('error', "{$user->name} is already " . ($current === 'admin' ? 'a leader' : 'an editor') . ' here.');
        }

        $user->entities()->syncWithoutDetaching([
            $entity->id => ['role' => 'editor', 'granted_by' => $request->user()->id],
        ]);
        $user->entities()->updateExistingPivot($entity->id, ['role' => 'editor', 'granted_by' => $request->user()->id]);
        $user->forgetEntityRoles();

        return back()->with('success', "{$user->name} is now an editor.");
    }

    /** Revoke an editor. Leaders and followers are not touched by this route. */
    public function destroy(Request $request, Entity $entity, User $user): RedirectResponse
    {
        $this->authorizeLeader($request, $entity);

        $role = $user->entities()->where('entities.id', $entity->id)->first()?->pivot->role;

        abort_unless($role === 'editor', 403, 'Only editors can be removed here.');

        $user->entities()->detach($entity->id);
        $user->forgetEntityRoles();

        return back()->with('success', "{$user->name} is no longer an editor.");
    }

    public function destroyInvitation(Request $request, Entity $entity, EntityInvitation $invitation): RedirectResponse
    {
        $this->authorizeLeader($request, $entity);

        abort_unless($invitation->entity_id === $entity->id, 404);

        $invitation->delete();

        return back()->with('success', 'Invitation removed.');
    }

    /** Leaders of this entity, and site admins; never editors or followers. */
    private function authorizeLeader(Request $request, Entity $entity): void
    {
        $user = $request->user();

        abort_unless($user->isAdmin() || $user->isEntityLeader($entity->id), 403);
    }
}
