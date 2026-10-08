<?php

namespace Tests\Unit;

use App\Support\YoutubeSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class YoutubeSourceTest extends TestCase
{
    #[DataProvider('links')]
    public function test_parse(string $input, ?array $expected): void
    {
        $this->assertSame($expected, YoutubeSource::parse($input));
    }

    public static function links(): array
    {
        $video = ['video_id' => 'dQw4w9WgXcQ'];
        $list = ['playlist_id' => 'PLabcdefghijklmnopqrstuvwxyz012345'];

        return [
            'watch'          => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10', $video],
            'no scheme'      => ['youtube.com/watch?v=dQw4w9WgXcQ', $video],
            'mobile'         => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', $video],
            'share'          => ['https://youtu.be/dQw4w9WgXcQ?si=abc', $video],
            'embed'          => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $video],
            'shorts'         => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', $video],
            'bare video id'  => ['dQw4w9WgXcQ', $video],
            'playlist link'  => ['https://www.youtube.com/playlist?list=PLabcdefghijklmnopqrstuvwxyz012345', $list],
            'watch in list'  => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PLabcdefghijklmnopqrstuvwxyz012345', $list],
            'bare list id'   => ['PLabcdefghijklmnopqrstuvwxyz012345', $list],
            'other host'     => ['https://evil.example/watch?v=dQw4w9WgXcQ', null],
            'lookalike host' => ['https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ', null],
            'bad id'         => ['https://www.youtube.com/watch?v=short', null],
            'injection'      => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ"onload="x', null],
            'script'         => ['javascript:alert(1)', null],
            'empty'          => ['  ', null],
        ];
    }

    public function test_embed_url_is_always_the_nocookie_host_and_loops(): void
    {
        $single = YoutubeSource::embedUrl(['video_id' => 'dQw4w9WgXcQ'], false);
        $this->assertStringStartsWith('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?playlist=dQw4w9WgXcQ&', $single);
        $this->assertStringContainsString('loop=1', $single);
        $this->assertStringNotContainsString('mute=1', $single);

        $list = YoutubeSource::embedUrl(['playlist_id' => 'PLabcdefghijklmnopqrstuvwxyz012345'], true);
        $this->assertStringStartsWith('https://www.youtube-nocookie.com/embed/videoseries?list=PLabc', $list);
        $this->assertStringContainsString('mute=1', $list);
    }
}
