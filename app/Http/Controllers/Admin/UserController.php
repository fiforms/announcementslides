<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        $users = User::with(['entities' => function ($q) {
                $q->select('entities.id', 'name', 'city', 'state', 'entity_type')
                  ->withPivot('role', 'granted_by');
            }])
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id'         => $u->id,
                'name'       => $u->name,
                'email'      => $u->email,
                'role'       => $u->role,
                'avatar_url' => $u->avatar_url,
                'created_at' => $u->created_at->toDateString(),
                'entities'   => $u->entities->map(fn ($e) => [
                    'id'          => $e->id,
                    'name'        => $e->name,
                    'city'        => $e->city,
                    'state'       => $e->state,
                    'entity_type' => $e->entity_type,
                    'role'        => $e->pivot->role,
                ]),
            ]);

        $invitations = UserInvitation::with('creator:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (UserInvitation $i) => [
                'id'         => $i->id,
                'email'      => $i->email,
                'role'       => $i->role,
                'created_by' => $i->creator ? [
                    'id'   => $i->creator->id,
                    'name' => $i->creator->name,
                ] : null,
                'created_at' => $i->created_at->toDateString(),
            ]);

        return Inertia::render('Admin/Users/Index', compact('users', 'invitations'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', 'in:admin,contributor,viewer,banned'],
        ]);

        // Prevent admins from demoting themselves.
        if ($user->id === $request->user()->id && $data['role'] !== 'admin') {
            return back()->with('error', 'You cannot change your own admin role.');
        }

        $user->update(['role' => $data['role']]);

        return back()->with('success', "Updated {$user->name}'s role to {$data['role']}.");
    }

    public function attachEntity(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'entity_id' => ['required', 'integer', 'exists:entities,id'],
            'role'      => ['required', 'in:viewer,admin'],
        ]);

        $user->entities()->syncWithoutDetaching([
            $data['entity_id'] => [
                'role'       => $data['role'],
                'granted_by' => $request->user()->id,
            ],
        ]);

        return back()->with('success', 'Entity relationship added.');
    }

    public function updateEntityRole(Request $request, User $user, Entity $entity): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', 'in:viewer,admin'],
        ]);

        $user->entities()->updateExistingPivot($entity->id, [
            'role'       => $data['role'],
            'granted_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Entity role updated.');
    }

    public function detachEntity(User $user, Entity $entity): RedirectResponse
    {
        $user->entities()->detach($entity->id);

        return back()->with('success', 'Entity relationship removed.');
    }

    public function storeInvitation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'unique:user_invitations,email'],
            'role'  => ['required', 'in:admin,contributor,viewer'],
        ]);

        // If the user already exists, just update their role directly.
        $existing = User::where('email', $data['email'])->first();
        if ($existing) {
            $existing->update(['role' => $data['role']]);
            return back()->with('success', "{$existing->name} already has an account — role set to {$data['role']} directly.");
        }

        UserInvitation::create([
            'email'      => $data['email'],
            'role'       => $data['role'],
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', "Invitation saved for {$data['email']}.");
    }

    public function destroyInvitation(UserInvitation $invitation): RedirectResponse
    {
        $invitation->delete();

        return back()->with('success', 'Invitation removed.');
    }
}
