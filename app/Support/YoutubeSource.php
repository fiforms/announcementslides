<?php

namespace App\Support;

/**
 * A YouTube video or playlist used as a show's background. Whatever a person
 * pastes (a watch / share / embed / shorts link, a playlist link, or a bare
 * id) is reduced to validated ids; only those are stored, and the embed URL
 * is always built here, on youtube-nocookie.com, so no other URL can reach a
 * player's iframe.
 */
class YoutubeSource
{
    public const EMBED_PREFIX = 'https://www.youtube-nocookie.com/embed/';

    private const VIDEO_ID = '/^[A-Za-z0-9_-]{11}$/';
    private const PLAYLIST_ID = '/^[A-Za-z0-9_-]{13,64}$/';

    /**
     * @return array{video_id?: string, playlist_id?: string}|null  null if nothing usable.
     *         A playlist link wins over the video in it (the whole list loops).
     */
    public static function parse(string $input): ?array
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        if (preg_match(self::VIDEO_ID, $input)) {
            return ['video_id' => $input];
        }
        if (preg_match(self::PLAYLIST_ID, $input) && preg_match('/^(PL|UU|LL|FL|RD|OL)/', $input)) {
            return ['playlist_id' => $input];
        }

        if (!preg_match('#^(?:https?://)?([^/?\#]+)(/[^?\#]*)?(?:\?([^\#]*))?#i', $input, $m)) {
            return null;
        }
        $host = strtolower(preg_replace('/^(www|m|music)\./', '', $m[1]));
        $path = $m[2] ?? '';
        parse_str($m[3] ?? '', $query);

        if (!in_array($host, ['youtube.com', 'youtube-nocookie.com', 'youtu.be'], true)) {
            return null;
        }

        $list = is_string($query['list'] ?? null) ? $query['list'] : null;
        if ($list !== null && preg_match(self::PLAYLIST_ID, $list)) {
            return ['playlist_id' => $list];
        }

        $video = null;
        if ($host === 'youtu.be') {
            $video = trim($path, '/');
        } elseif (is_string($query['v'] ?? null)) {
            $video = $query['v'];
        } elseif (preg_match('#^/(?:embed|shorts|live|v)/([^/]+)#', $path, $p)) {
            $video = $p[1];
        }

        return $video !== null && preg_match(self::VIDEO_ID, $video) ? ['video_id' => $video] : null;
    }

    /** The embed URL for stored ids: autoplaying, looping, no controls. */
    public static function embedUrl(array $source, bool $muted): string
    {
        $params = [
            'autoplay' => 1, 'loop' => 1, 'controls' => 0, 'playsinline' => 1, 'rel' => 0,
            'modestbranding' => 1, 'iv_load_policy' => 3, 'disablekb' => 1, 'fs' => 0,
            // Lets the player be paused and resumed by postMessage.
            'enablejsapi' => 1,
        ];
        if ($muted) {
            $params['mute'] = 1;
        }

        if (!empty($source['playlist_id'])) {
            $path = 'videoseries';
            $params = ['list' => $source['playlist_id']] + $params;
        } else {
            // A single video loops only when it is its own playlist.
            $path = $source['video_id'];
            $params = ['playlist' => $source['video_id']] + $params;
        }

        return self::EMBED_PREFIX . $path . '?' . http_build_query($params);
    }

    /** The stored value as the Show Editor shows it. */
    public static function describe(?array $stored): ?array
    {
        if (!$stored) {
            return null;
        }

        return [
            'kind'  => !empty($stored['playlist_id']) ? 'playlist' : 'video',
            'id'    => $stored['playlist_id'] ?? $stored['video_id'] ?? null,
            'muted' => (bool) ($stored['muted'] ?? false),
        ];
    }
}
