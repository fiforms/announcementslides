<?php

namespace Tests\Unit;

use App\Models\SlideAnnouncerRelease;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Release versions are <platform X.Y.Z> or the pair <platform>_<product>. */
class SlideAnnouncerReleaseVersionTest extends TestCase
{
    public function test_a_pair_parses_to_platform_then_product_and_a_plain_version_has_product_zero(): void
    {
        $this->assertSame([0, 4, 0, 0, 1, 1], SlideAnnouncerRelease::versionCore('0.4.0_0.1.1'));
        $this->assertSame([0, 4, 0, 0, 1, 1], SlideAnnouncerRelease::versionCore('0.4.0_0.1.1-8066cfc-dirty-slideannouncer-e4b6392'));
        $this->assertSame([0, 4, 0, 0, 0, 0], SlideAnnouncerRelease::versionCore('0.4.0'));
        $this->assertSame([0, 4, 0, 0, 0, 0], SlideAnnouncerRelease::versionCore('0.4.0-8066cfc-slideannouncer-b0.1.0-f0.1.0-8f51f4b'));
        $this->assertNull(SlideAnnouncerRelease::versionCore('dev'));
        $this->assertNull(SlideAnnouncerRelease::versionCore(null));
    }

    #[DataProvider('orderings')]
    public function test_ordering(string $older, string $newer): void
    {
        $this->assertLessThan(0, SlideAnnouncerRelease::compareVersions($older, $newer));
        $this->assertGreaterThan(0, SlideAnnouncerRelease::compareVersions($newer, $older));
    }

    public static function orderings(): array
    {
        return [
            'product-only bump'         => ['0.4.0_0.1.0', '0.4.0_0.1.1'],
            'platform beats product'    => ['0.4.0_0.1.9', '0.4.1_0.1.0'],
            'legacy to first pair'      => ['0.4.0', '0.4.0_0.1.0'],
            'numeric, not lexical'      => ['0.4.9_0.0.1', '0.4.10_0.0.1'],
            'device with a hash suffix' => ['0.4.0_0.1.0-8066cfc-slideannouncer-e4b6392', '0.4.0_0.1.1'],
        ];
    }

    public function test_the_same_pair_is_equal_whatever_follows_it(): void
    {
        $this->assertSame(0, SlideAnnouncerRelease::compareVersions('0.4.0_0.1.1', '0.4.0_0.1.1-8066cfc-dirty'));
    }

    #[DataProvider('filenames')]
    public function test_parse_filename(string $filename, ?array $expected): void
    {
        $this->assertSame($expected, SlideAnnouncerRelease::parseFilename($filename));
    }

    public static function filenames(): array
    {
        return [
            'plain full'        => ['slideannouncer-0.4.0.raucb', ['version' => '0.4.0', 'release_type' => 'full', 'required_base_version' => null]],
            'pair full'         => ['slideannouncer-0.4.0_0.1.1.raucb', ['version' => '0.4.0_0.1.1', 'release_type' => 'full', 'required_base_version' => null]],
            'pair image'        => ['slideannouncer-0.4.1_0.1.0.img.xz', ['version' => '0.4.1_0.1.0', 'release_type' => 'full', 'required_base_version' => null]],
            'legacy to pair'    => ['slideannouncer-0.4.1_0.1.0.hotfix.from.0.4.0.raucb', ['version' => '0.4.1_0.1.0', 'release_type' => 'hotfix', 'required_base_version' => '0.4.0']],
            'pair to pair'      => ['slideannouncer-0.4.1_0.1.1.hotfix.from.0.4.1_0.1.0.raucb', ['version' => '0.4.1_0.1.1', 'release_type' => 'hotfix', 'required_base_version' => '0.4.1_0.1.0']],
            'app archive'       => ['slide-announcer-local-app-0.4.0_0.1.1-8066cfc-slideannouncer-e4b6392.tar.gz', ['version' => '0.4.0_0.1.1', 'release_type' => 'full', 'required_base_version' => null]],
            'legacy app archive' => ['slide-announcer-local-app-0.4.0-8066cfc-slideannouncer-b0.1.0-f0.1.0-8f51f4b.tar.gz', ['version' => '0.4.0', 'release_type' => 'full', 'required_base_version' => null]],
            'unrelated'         => ['notes.txt', null],
            'plus is not a separator' => ['slideannouncer-0.4.0+0.1.1.raucb', null],
        ];
    }
}
