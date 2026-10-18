<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Trust the reverse proxy's X-Forwarded-* headers so $request->ip() is the
 * real client. TRUSTED_PROXIES is a comma-separated list of proxy IPs/CIDRs,
 * or '*' to trust any upstream. Read at request time so .env is loaded.
 */
class TrustProxies extends Middleware
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO;

    protected function proxies()
    {
        $value = trim((string) env('TRUSTED_PROXIES', '*'));

        if ($value === '*' || $value === '**') {
            return $value;
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)))) ?: null;
    }
}
