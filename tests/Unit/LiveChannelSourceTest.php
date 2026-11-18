<?php

namespace Tests\Unit;

use App\Support\LiveChannelSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LiveChannelSourceTest extends TestCase
{
    private const ZYPE_KEY = 'tq1hOEn0RGXLdpr2Z69t61rs2EPMmOk6Y4vh8YGDCBqNmUuSMfer2kzdp0nBup2z';
    private const HLS = 'https://3abn.tulixcdn.com/3abn2/3abn_live/smil:3abn_live.smil/playlist.m3u8';

    #[DataProvider('accepted')]
    public function test_parses_supported_players(string $input, array $expected): void
    {
        $this->assertSame($expected, LiveChannelSource::parse($input));
    }

    public static function accepted(): array
    {
        $zype = ['provider' => 'zype', 'id' => '69d0a21416d3588e4d7f9349', 'api_key' => self::ZYPE_KEY];

        return [
            'jetstream embed' => ['https://embed.jetstream.studio/embed/jsl:0sUSK6VA7GTNxQaY8E8L', ['provider' => 'jetstream', 'id' => 'jsl:0sUSK6VA7GTNxQaY8E8L']],
            'jetstream bare id' => ['jsl:0sUSK6VA7GTNxQaY8E8L', ['provider' => 'jetstream', 'id' => 'jsl:0sUSK6VA7GTNxQaY8E8L']],
            'jetstream iframe code' => ['<iframe src="https://embed.jetstream.studio/embed/jsl:0sUSK6VA7GTNxQaY8E8L" allowfullscreen></iframe>', ['provider' => 'jetstream', 'id' => 'jsl:0sUSK6VA7GTNxQaY8E8L']],
            'zype embed' => ['https://player.zype.com/embed/69d0a21416d3588e4d7f9349.html?api_key=' . self::ZYPE_KEY . '&autoplay=false&controls=true', $zype],
            'zype iframe with entities' => ['<iframe src="https://player.zype.com/embed/69d0a21416d3588e4d7f9349.html?api_key=' . self::ZYPE_KEY . '&amp;autoplay=false"></iframe>', $zype],
            'hls stream' => [self::HLS, ['provider' => 'hls', 'url' => self::HLS]],
            'hls with token' => ['https://cdn.example.org/live/index.m3u8?token=abc', ['provider' => 'hls', 'url' => 'https://cdn.example.org/live/index.m3u8?token=abc']],
        ];
    }

    #[DataProvider('rejected')]
    public function test_rejects_everything_else(string $input): void
    {
        $this->assertNull(LiveChannelSource::parse($input));
    }

    public static function rejected(): array
    {
        return [
            'empty' => [''],
            'watch page' => ['https://hopetv.org/watch/hope-channel'],
            'lookalike jetstream host' => ['https://embed.jetstream.studio.evil.example/embed/jsl:0sUSK6VA7GTNxQaY8E8L'],
            'http jetstream' => ['http://embed.jetstream.studio/embed/jsl:0sUSK6VA7GTNxQaY8E8L'],
            'jetstream bad id' => ['https://embed.jetstream.studio/embed/<script>'],
            'zype without key' => ['https://player.zype.com/embed/69d0a21416d3588e4d7f9349.html'],
            'zype bad id' => ['https://player.zype.com/embed/nothex.html?api_key=' . self::ZYPE_KEY],
            'http hls' => ['http://3abn.tulixcdn.com/live/playlist.m3u8'],
            'hls with credentials' => ['https://user:pw@cdn.example.org/live.m3u8'],
            'hls with quote' => ['https://cdn.example.org/a".m3u8'],
            'not m3u8' => ['https://cdn.example.org/live.mp4'],
            'javascript' => ['javascript:alert(1)'],
            'iframe to elsewhere' => ['<iframe src="https://evil.example/embed/jsl:0sUSK6VA7GTNxQaY8E8L"></iframe>'],
        ];
    }

    public function test_embed_urls_are_rebuilt_from_ids(): void
    {
        $this->assertSame(
            'https://embed.jetstream.studio/embed/jsl:0sUSK6VA7GTNxQaY8E8L',
            LiveChannelSource::embedUrl('jetstream', ['id' => 'jsl:0sUSK6VA7GTNxQaY8E8L'], true)
        );

        $zype = LiveChannelSource::embedUrl('zype', ['id' => '69d0a21416d3588e4d7f9349', 'api_key' => self::ZYPE_KEY], true);
        $this->assertStringStartsWith('https://player.zype.com/embed/69d0a21416d3588e4d7f9349.html?api_key=' . self::ZYPE_KEY, $zype);
        $this->assertStringContainsString('autoplay=true', $zype);
        $this->assertStringContainsString('controls=false', $zype);
        $this->assertStringContainsString('mute=true', $zype);
        $this->assertStringNotContainsString('mute=', LiveChannelSource::embedUrl('zype', ['id' => '69d0a21416d3588e4d7f9349', 'api_key' => self::ZYPE_KEY], false));

        $this->assertSame(self::HLS, LiveChannelSource::embedUrl('hls', ['url' => self::HLS], false));
    }

    public function test_a_tampered_stored_source_builds_nothing(): void
    {
        $this->assertNull(LiveChannelSource::embedUrl('jetstream', ['id' => '"><script>'], false));
        $this->assertNull(LiveChannelSource::embedUrl('zype', ['id' => '69d0a21416d3588e4d7f9349'], false));
        $this->assertNull(LiveChannelSource::embedUrl('hls', ['url' => 'http://insecure.example/a.m3u8'], false));
        $this->assertNull(LiveChannelSource::embedUrl('unknown', ['id' => 'x'], false));
    }
}
