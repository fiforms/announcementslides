<?php

namespace App\Http\Middleware;

use App\Support\FirstRunSetup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While the app has no users, send people who land on the home, login or
 * register page to the setup wizard, so `docker compose up` followed by
 * opening the site just works. Only those entry pages are redirected; every
 * other route behaves as before.
 */
class RedirectToSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            config('setup.redirect')
            && $request->isMethod('GET')
            && $request->routeIs('slides.index', 'login', 'register')
            && FirstRunSetup::needed()
        ) {
            return redirect()->route('setup.show');
        }

        return $next($request);
    }
}
