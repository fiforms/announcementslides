<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            'authMethod' => $user->google_id ? 'google' : 'password',
            'nearbyRadiusMiles' => (int) $user->setting('nearby_radius_miles', config('slides.nearby_radius_miles')),
            'subscriptions' => $user->entities()
                ->orderBy('name')
                ->get(['entities.id', 'entities.name', 'entities.city', 'entities.state', 'entities.entity_type'])
                ->map(fn ($e) => [
                    'id'          => $e->id,
                    'name'        => $e->name,
                    'city'        => $e->city,
                    'state'       => $e->state,
                    'entity_type' => $e->entity_type,
                    'role'        => $e->pivot->role,
                ]),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Update the user's per-user settings (e.g. nearby sharing radius).
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nearby_radius_miles' => ['required', 'integer', 'min:1', 'max:500'],
        ]);

        $request->user()->putSetting('nearby_radius_miles', $validated['nearby_radius_miles']);

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
