<?php

namespace App\Support;

/**
 * A live channel's player, one adapter per provider. Whatever an admin
 * pastes (the provider's embed URL, or the iframe `src` copied from the
 * broadcaster's watch page) is reduced to validated ids; only those are
 * stored, and the embed URL is always rebuilt here from a fixed prefix, so
 * no other URL can reach a player's iframe. The players (web
 * ShowFrameLayer.vue and the device's) check the same prefixes again.
 *
 * Two kinds of player:
 *  - 'iframe': the provider's own embed page. No custom script: we cannot
 *    script a cross-origin iframe, and pushing code to devices is exactly
 *    what this design avoids. Its URL options (autoplay, mute) are all we use.
 *  - 'hls': a bare HLS stream (.m3u8) played in our own <video> with hls.js.
 *    No third-party code runs at all and we control mute/pause, so any https
 *    host is allowed; it must send CORS headers to be playable.
 */
class LiveChannelSource
{
    public const PROVIDERS = [
        // Hope Channel's player. No URL option for autoplay or mute: on a
        // Slide Announcer, Chromium's no-gesture autoplay policy decides.
        'jetstream' => [
            'label'  => 'JetStream',
            'prefix' => 'https://embed.jetstream.studio/embed/',
            'mute'   => false,
        ],
        // Amazing Facts TV's player. The api_key is the public player key
        // the broadcaster publishes in its own embed code.
        'zype' => [
            'label'  => 'Zype',
            'prefix' => 'https://player.zype.com/embed/',
            'mute'   => true,
        ],
        // A direct HLS stream, e.g. 3ABN's, whose watch page can't be framed
        // but whose public stream sends `Access-Control-Allow-Origin: *`.
        'hls' => [
            'label'  => 'HLS stream',
            'prefix' => 'https://',
            'mute'   => true,
        ],
    ];

    /** How a player renders each provider. */
    public static function kind(string $provider): string
    {
        return $provider === 'hls' ? 'hls' : 'iframe';
    }

    private const JETSTREAM_ID = '/^js[a-z]{1,3}:[A-Za-z0-9]{8,64}$/';
    private const ZYPE_VIDEO_ID = '/^[0-9a-f]{24}$/';
    private const ZYPE_API_KEY = '/^[A-Za-z0-9_-]{16,128}$/';

    /**
     * @return array{provider: string, id?: string, api_key?: string, url?: string}|null  null if not a supported player.
     */
    public static function parse(string $input): ?array
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        // A pasted <iframe …> tag: use its src.
        if (preg_match('/<iframe\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $input, $m)) {
            $input = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        }
        if (preg_match(self::JETSTREAM_ID, $input)) {
            return ['provider' => 'jetstream', 'id' => $input];
        }

        if (!preg_match('#^https://([^/?\#]+)(/[^?\#]*)?(?:\?([^\#]*))?#i', $input, $m)) {
            return null;
        }
        $host = strtolower($m[1]);
        $path = $m[2] ?? '';
        parse_str($m[3] ?? '', $query);

        if ($host === 'embed.jetstream.studio'
            && preg_match('#^/embed/([^/]+)/?$#', $path, $p) && preg_match(self::JETSTREAM_ID, $p[1])) {
            return ['provider' => 'jetstream', 'id' => $p[1]];
        }

        if (preg_match('#\.m3u8$#i', $path)) {
            return self::validHls($input) ? ['provider' => 'hls', 'url' => $input] : null;
        }

        if ($host === 'player.zype.com' && preg_match('#^/embed/([0-9a-f]{24})(?:\.html|\.js)?$#', $path, $p)) {
            $key = $query['api_key'] ?? null;
            if (is_string($key) && preg_match(self::ZYPE_API_KEY, $key) && preg_match(self::ZYPE_VIDEO_ID, $p[1])) {
                return ['provider' => 'zype', 'id' => $p[1], 'api_key' => $key];
            }
        }

        return null;
    }

    /** The embed URL for a stored source, or null if it no longer validates. */
    public static function embedUrl(string $provider, array $source, bool $muted): ?string
    {
        $id = $source['id'] ?? '';

        return match ($provider) {
            'jetstream' => preg_match(self::JETSTREAM_ID, $id)
                ? self::PROVIDERS['jetstream']['prefix'] . $id
                : null,
            'zype' => preg_match(self::ZYPE_VIDEO_ID, $id) && preg_match(self::ZYPE_API_KEY, $source['api_key'] ?? '')
                ? self::PROVIDERS['zype']['prefix'] . $id . '.html?' . http_build_query(array_filter([
                    'api_key'  => $source['api_key'],
                    'autoplay' => 'true',
                    'controls' => 'false',
                    'mute'     => $muted ? 'true' : null,
                ]))
                : null,
            'hls' => self::validHls($source['url'] ?? '') ? $source['url'] : null,
            default => null,
        };
    }

    /** An https URL to a .m3u8 playlist: no credentials, whitespace or fragment. */
    private static function validHls(string $url): bool
    {
        if (strlen($url) > 1000 || preg_match('/[\s"\'<>\\\\]/', $url)) {
            return false;
        }
        $parts = parse_url($url);

        return $parts !== false
            && ($parts['scheme'] ?? '') === 'https'
            && preg_match('/^[a-z0-9.-]+$/i', $parts['host'] ?? '')
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment'])
            && preg_match('#\.m3u8$#i', $parts['path'] ?? '');
    }

    public static function supportsMute(string $provider): bool
    {
        return (bool) (self::PROVIDERS[$provider]['mute'] ?? false);
    }

    public static function label(string $provider): string
    {
        return self::PROVIDERS[$provider]['label'] ?? $provider;
    }
}
