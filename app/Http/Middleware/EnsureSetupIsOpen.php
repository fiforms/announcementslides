<?php

namespace App\Http\Middleware;

use App\Support\FirstRunSetup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The setup routes exist only while there are no users; afterwards they 404. */
class EnsureSetupIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(FirstRunSetup::needed(), 404);

        return $next($request);
    }
}
