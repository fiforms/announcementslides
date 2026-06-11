<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback()
    {
        $googleUser = Socialite::driver('google')->user();

        $user = User::where('google_id', $googleUser->getId())->first();

        if (! $user) {
            // Link existing account by email if one exists
            $user = User::where('email', $googleUser->getEmail())->first();

            if ($user) {
                $user->update([
                    'google_id' => $googleUser->getId(),
                    'avatar_url' => $googleUser->getAvatar(),
                ]);
            } else {
                $invitation = UserInvitation::where('email', $googleUser->getEmail())->first();

                $user = User::create([
                    'name'               => $googleUser->getName(),
                    'email'              => $googleUser->getEmail(),
                    'google_id'          => $googleUser->getId(),
                    'avatar_url'         => $googleUser->getAvatar(),
                    'email_verified_at'  => now(),
                    'role'               => $invitation?->role ?? 'viewer',
                ]);

                $invitation?->delete();
            }
        }

        Auth::login($user, true);

        $home = $user->isAdmin()
            ? route('admin.dashboard', absolute: false)
            : route('slides.index', absolute: false);

        return redirect()->intended($home);
    }
}
