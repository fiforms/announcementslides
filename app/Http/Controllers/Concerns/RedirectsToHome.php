<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;

/**
 * Where an authenticated user belongs when nothing more specific applies —
 * after logging in, registering, verifying an email, or confirming a
 * password.
 *
 * Breeze scaffolds these redirects as route('dashboard'), a name this app
 * never registered: the only dashboard here is `admin.dashboard`, behind
 * EnsureAdmin, and a freshly-registered user is a viewer. Every one of those
 * call sites therefore threw RouteNotFoundException and 500'd, which is what
 * took out registration, email verification and password confirmation.
 *
 * AuthenticatedSessionController had already worked out the right answer for
 * login; this is that logic, shared, so the other four paths agree with it
 * rather than each inventing a destination.
 */
trait RedirectsToHome
{
    protected function homeRouteFor(?User $user): string
    {
        return $user?->isAdmin()
            ? route('admin.dashboard', absolute: false)
            : route('slides.index', absolute: false);
    }
}
