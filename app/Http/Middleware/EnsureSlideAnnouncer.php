<?php

namespace App\Http\Middleware;

use App\Models\SlideAnnouncer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSlideAnnouncer
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();

        abort_unless($device instanceof SlideAnnouncer, 403);
        abort_if($device->isRevoked(), 401);

        return $next($request);
    }
}
