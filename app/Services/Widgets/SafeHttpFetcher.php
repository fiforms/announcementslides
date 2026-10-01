<?php

namespace App\Services\Widgets;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The only way widget data leaves the server. GET-only, https-only on the
 * default port, to hosts whose every resolved address is public — and the
 * connection is pinned to the address that was checked (CURLOPT_RESOLVE),
 * so a DNS answer that changes between check and connect (rebinding) can't
 * redirect it inward. Redirects are followed by hand, each hop re-checked
 * and also approved by the caller's $allowHop rule. No caller headers or
 * cookies are forwarded; bodies are capped while streaming.
 */
class SafeHttpFetcher
{
    public function __construct(private HostResolver $resolver) {}

    /**
     * @param  Closure(string): bool  $allowHop  extra rule for each redirect target
     * @return array{body: string, content_type: string, url: string}
     *
     * @throws WidgetFetchException
     */
    public function get(string $url, int $maxBytes, Closure $allowHop): array
    {
        // Scheme/host/port rules only; path-level allowlist rules are the
        // caller's (via $allowHop for redirects).
        $current = UrlPolicy::normalize($url, false) ?? throw new WidgetFetchException('blocked_url');

        for ($hop = 0; $hop <= config('widgets.fetch.max_redirects'); $hop++) {
            $host = UrlPolicy::host($current);
            $ip = $this->publicAddress($host);

            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'stream'          => true,
                    'decode_content'  => true,
                    'curl'            => [
                        CURLOPT_RESOLVE         => [$this->resolveEntry($host, $ip)],
                        CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
                        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                    ],
                ])
                    ->withHeaders(['User-Agent' => config('widgets.fetch.user_agent'), 'Accept' => '*/*'])
                    ->withoutRedirecting()
                    ->timeout(config('widgets.fetch.timeout_seconds'))
                    ->connectTimeout(config('widgets.fetch.timeout_seconds'))
                    ->get($current);
            } catch (ConnectionException) {
                throw new WidgetFetchException('upstream_unreachable');
            }

            $status = $response->status();
            if ($status >= 300 && $status < 400 && $response->header('Location')) {
                $next = UrlPolicy::normalize($this->resolveLocation($current, $response->header('Location')), false);
                if ($next === null || !$allowHop($next)) {
                    throw new WidgetFetchException('blocked_redirect');
                }
                $current = $next;
                continue;
            }
            if ($status !== 200) {
                throw new WidgetFetchException('upstream_status', $status);
            }

            return [
                'body'         => $this->readCapped($response, $maxBytes),
                'content_type' => strtolower((string) $response->header('Content-Type')),
                'url'          => $current,
            ];
        }

        throw new WidgetFetchException('too_many_redirects');
    }

    private function publicAddress(string $host): string
    {
        $ips = $this->resolver->resolve($host);
        if (!$ips) {
            throw new WidgetFetchException('dns_failed');
        }
        // Every answer must be public — otherwise a name with one public and
        // one private record could still be steered inward.
        foreach ($ips as $ip) {
            if (!UrlPolicy::isPublicIp($ip)) {
                throw new WidgetFetchException('blocked_address');
            }
        }

        return $ips[0];
    }

    private function resolveEntry(string $host, string $ip): string
    {
        $host = trim($host, '[]');

        return $host . ':443:' . (str_contains($ip, ':') ? "[{$ip}]" : $ip);
    }

    private function readCapped($response, int $maxBytes): string
    {
        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        while (!$stream->eof()) {
            $body .= $stream->read(65536);
            if (strlen($body) > $maxBytes) {
                $stream->close();
                throw new WidgetFetchException('too_large');
            }
        }

        return $body;
    }

    private function resolveLocation(string $base, string $location): string
    {
        $location = trim($location);
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');

        return $origin . $dir . $location;
    }
}
