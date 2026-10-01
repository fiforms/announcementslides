<?php

namespace App\Services\Widgets;

use App\Models\Widget;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * Serves a widget endpoint's data: builds the upstream URL server-side from
 * the manifest's template and already-validated parameters (callers never
 * supply a URL), fetches it through SafeHttpFetcher, checks it's the kind of
 * data the endpoint promised (`expect`), and caches the processed result in
 * a shared cache — so every screen showing the same calendar costs one
 * upstream request per TTL. A last-good copy is served while the upstream
 * is failing.
 */
class WidgetDataService
{
    public function __construct(private SafeHttpFetcher $fetcher) {}

    /**
     * @param  array<string, mixed>  $params  output of WidgetParams::clean()
     * @param  array<string, mixed>  $args  runtime args from the widget (untrusted; checked
     *                                      against the endpoint's declared `args`)
     * @return array{data: mixed, fetched_at: int, stale: bool}
     *
     * @throws WidgetFetchException
     */
    public function get(Widget $widget, string $endpointKey, array $params, string $callerKey, array $args = []): array
    {
        $endpoint = $widget->manifest['endpoints'][$endpointKey] ?? throw new WidgetFetchException('unknown_endpoint');
        [$url, $allowHop] = $this->buildUrl($widget, $endpoint, $params, $this->cleanArgs($endpoint, $args));

        $expect = $endpoint['expect'];
        $ttl = max(config('widgets.fetch.min_ttl'), $endpoint['ttl'] ?? config('widgets.fetch.default_ttl'));
        $days = $endpoint['days'] ?? config('widgets.fetch.ical_default_days');
        $key = 'widget-data:' . sha1("{$expect}|{$days}|{$url}");

        $cached = Cache::get($key);
        if ($cached && now()->timestamp - $cached['fetched_at'] < $ttl) {
            return $cached + ['stale' => false];
        }

        // One upstream fetch per key at a time; everyone else gets the
        // stale copy (or waits briefly when there's none yet).
        $lock = Cache::lock($key . ':lock', 15);
        if (!$lock->get()) {
            if ($cached) {
                return $cached + ['stale' => true];
            }
            try {
                $lock->block(10);
            } catch (LockTimeoutException) {
                throw new WidgetFetchException('upstream_busy');
            }
            $cached = Cache::get($key);
            if ($cached && now()->timestamp - $cached['fetched_at'] < $ttl) {
                $lock->release();
                return $cached + ['stale' => false];
            }
        }

        try {
            $this->throttle($callerKey, UrlPolicy::host($url));
            $result = $this->fetcher->get($url, config("widgets.fetch.max_bytes.{$expect}"), $allowHop);
            $entry = ['data' => $this->process($expect, $result['body'], $days), 'fetched_at' => now()->timestamp];
            Cache::put($key, $entry, $ttl + config('widgets.fetch.stale_seconds'));
            $this->log($widget, $endpointKey, $url, 'ok', strlen($result['body']));

            return $entry + ['stale' => false];
        } catch (WidgetFetchException $e) {
            $this->log($widget, $endpointKey, $url, $e->getMessage());
            if ($cached) {
                return $cached + ['stale' => true];
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{0: string, 1: \Closure(string): bool}  URL and redirect rule
     */
    /**
     * Declared args only, each validated like a parameter; an arg with no
     * value and no default makes the request invalid.
     *
     * @return array<string, mixed>
     */
    private function cleanArgs(array $endpoint, array $args): array
    {
        $clean = [];
        foreach ($endpoint['args'] ?? [] as $key => $def) {
            $value = $args[$key] ?? $def['default'] ?? null;
            if ($value === null || $value === '') {
                throw new WidgetFetchException('invalid_args');
            }
            if ($def['type'] === 'boolean' && is_string($value)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $value;
            }
            try {
                $clean[$key] = WidgetParams::cleanOne($key, $def, $value);
            } catch (WidgetPackageException) {
                throw new WidgetFetchException('invalid_args');
            }
        }

        return $clean;
    }

    public function buildUrl(Widget $widget, array $endpoint, array $params, array $args = []): array
    {
        $template = $endpoint['url'];

        if (preg_match('/^\{([a-z][a-z0-9_]*)\}$/', $template, $m)) {
            $url = $params[$m[1]] ?? '';
            if ($url === '') {
                throw new WidgetFetchException('not_configured');
            }
            // Re-check on every fetch: the allowlist may have been narrowed
            // since the slide was saved.
            $allow = $widget->allowFor($m[1]);
            if (UrlPolicy::normalize($url) !== $url || !UrlPolicy::allowed($url, $allow)) {
                throw new WidgetFetchException('blocked_url');
            }

            return [$url, function (string $hop) use ($allow) {
                $strict = UrlPolicy::normalize($hop);
                return $strict !== null && UrlPolicy::allowed($strict, $allow);
            }];
        }

        $missing = false;
        $url = preg_replace_callback('/\{([a-z0-9_:]+)\}/', function ($m) use ($widget, $params, $args, &$missing) {
            $value = match (true) {
                str_starts_with($m[1], 'secret:') => $widget->setting(substr($m[1], 7)),
                str_starts_with($m[1], 'arg:')    => $args[substr($m[1], 4)] ?? null,
                default                           => $params[$m[1]] ?? null,
            };
            if ($value === null || $value === '') {
                $missing = true;
                return '';
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_float($value)) {
                // Plain decimal, never PHP's 1.0E-5 notation.
                $value = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
            }

            return rawurlencode((string) $value);
        }, $template);

        if ($missing) {
            throw new WidgetFetchException('not_configured');
        }
        $url = UrlPolicy::normalize($url, false) ?? throw new WidgetFetchException('blocked_url');
        $host = UrlPolicy::host($url);

        // A fixed-host endpoint may only redirect within its own host.
        return [$url, fn (string $hop) => UrlPolicy::host($hop) === $host];
    }

    private function throttle(string $callerKey, string $host): void
    {
        foreach ([
            "widget-fetch:caller:{$callerKey}" => config('widgets.fetch.per_caller_limit'),
            "widget-fetch:host:{$host}"        => config('widgets.fetch.per_host_limit'),
        ] as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                throw new WidgetFetchException('rate_limited');
            }
        }
        RateLimiter::hit("widget-fetch:caller:{$callerKey}", 60);
        RateLimiter::hit("widget-fetch:host:{$host}", 60);
    }

    private function process(string $expect, string $body, int $days): mixed
    {
        return match ($expect) {
            'ical' => $this->parseIcal($body, $days),
            'json' => $this->parseJson($body),
            'text' => mb_convert_encoding($body, 'UTF-8', 'UTF-8'),
        };
    }

    private function parseJson(string $body): mixed
    {
        $data = json_decode($body, true, 64);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new WidgetFetchException('invalid_response');
        }

        return $data;
    }

    /**
     * ICS → upcoming events as plain JSON, recurrences expanded, within
     * [now − 1 day, now + $days]. Widgets get structured data, never the
     * raw feed.
     */
    private function parseIcal(string $body, int $days): array
    {
        if (!str_starts_with(ltrim($body, "\xEF\xBB\xBF \t\r\n"), 'BEGIN:VCALENDAR')) {
            throw new WidgetFetchException('invalid_response');
        }

        try {
            $calendar = Reader::read($body, Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);
            if (!$calendar instanceof VCalendar) {
                throw new WidgetFetchException('invalid_response');
            }
            $start = CarbonImmutable::now()->subDay();
            $end = CarbonImmutable::now()->addDays($days);
            $expanded = $calendar->expand($start, $end);
        } catch (WidgetFetchException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new WidgetFetchException('invalid_response');
        }

        $events = [];
        foreach ($expanded->select('VEVENT') as $event) {
            if (strtoupper((string) ($event->STATUS ?? '')) === 'CANCELLED') {
                continue;
            }
            $dtStart = $event->DTSTART;
            $allDay = $dtStart && !$dtStart->hasTime();
            $startAt = $dtStart?->getDateTime();
            $endAt = isset($event->DTEND) ? $event->DTEND->getDateTime()
                : (isset($event->DURATION) ? $startAt?->add($event->DURATION->getDateInterval()) : $startAt);
            if (!$startAt) {
                continue;
            }

            $events[] = [
                'uid'         => mb_substr((string) ($event->UID ?? ''), 0, 200),
                'title'       => mb_substr(trim((string) ($event->SUMMARY ?? '')), 0, 300),
                'location'    => mb_substr(trim((string) ($event->LOCATION ?? '')), 0, 300),
                'description' => mb_substr(trim((string) ($event->DESCRIPTION ?? '')), 0, 1000),
                // All-day events are dates, not instants — sent without a
                // time zone so they don't shift a day for distant viewers.
                'start'       => $allDay ? $startAt->format('Y-m-d') : $startAt->format(DATE_ATOM),
                'end'         => $allDay ? $endAt->format('Y-m-d') : $endAt->format(DATE_ATOM),
                'all_day'     => $allDay,
            ];
        }

        usort($events, fn ($a, $b) => strcmp($this->sortKey($a), $this->sortKey($b)));

        return [
            'name'     => mb_substr(trim((string) ($calendar->{'X-WR-CALNAME'} ?? '')), 0, 200),
            'timezone' => (string) ($calendar->{'X-WR-TIMEZONE'} ?? ''),
            'events'   => array_slice($events, 0, config('widgets.fetch.ical_max_events')),
        ];
    }

    private function sortKey(array $event): string
    {
        return $event['all_day']
            ? $event['start'] . 'T00:00:00'
            : CarbonImmutable::parse($event['start'])->utc()->format('Y-m-d\TH:i:s');
    }

    /**
     * Every upstream fetch goes to the log; failures are also kept as a
     * short per-widget list for the admin Widgets page.
     */
    private function log(Widget $widget, string $endpoint, string $url, string $outcome, ?int $bytes = null): void
    {
        $host = UrlPolicy::host($url);
        Log::info('Widget data fetch', [
            'widget'   => $widget->slug,
            'endpoint' => $endpoint,
            'host'     => $host,
            'outcome'  => $outcome,
            'bytes'    => $bytes,
        ]);

        if ($outcome !== 'ok') {
            $errors = Cache::get(self::errorsKey($widget->slug), []);
            array_unshift($errors, ['at' => now()->timestamp, 'endpoint' => $endpoint, 'host' => $host, 'reason' => $outcome]);
            Cache::put(self::errorsKey($widget->slug), array_slice($errors, 0, 20), now()->addWeek());
        }
    }

    public static function errorsKey(string $slug): string
    {
        return "widget-errors:{$slug}";
    }
}
