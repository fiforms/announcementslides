<?php

namespace App\Support;

use App\Models\User;

/**
 * Whether the app is still waiting for its first user. The single source of
 * truth for the setup wizard's gate: the wizard exists exactly while there
 * are no users, and never again afterwards.
 *
 * Deliberately not cached: it is a cheap indexed query, only evaluated on the
 * handful of routes that care, and any cache could serve a stale "yes" in a
 * long-lived php-fpm worker after another worker created the first user.
 */
class FirstRunSetup
{
    public static function needed(): bool
    {
        return ! User::query()->exists();
    }

    public static function tokenRequired(): bool
    {
        return config('setup.token') !== null;
    }
}
