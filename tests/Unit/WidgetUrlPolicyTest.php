<?php

namespace Tests\Unit;

use App\Services\Widgets\UrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WidgetUrlPolicyTest extends TestCase
{
    private const GOOGLE = 'https://calendar.google.com/calendar/ical/carolinasda.org_47pbgk242tl968925qivqas0s8%40group.calendar.google.com/public/basic.ics';

    public function test_normalize_keeps_a_real_google_feed_url_intact(): void
    {
        $this->assertSame(self::GOOGLE, UrlPolicy::normalize(self::GOOGLE));
    }

    public function test_webcal_is_treated_as_https(): void
    {
        $this->assertSame('https://p01-calendars.icloud.com/x.ics', UrlPolicy::normalize('webcal://p01-calendars.icloud.com/x.ics'));
    }

    public static function rejectedUrls(): array
    {
        return [
            'http'            => ['http://calendar.google.com/calendar/ical/x.ics'],
            'other port'      => ['https://calendar.google.com:8443/calendar/ical/x.ics'],
            'userinfo'        => ['https://calendar.google.com@evil.com/calendar/ical/x.ics'],
            'user:pass'       => ['https://a:b@calendar.google.com/calendar/ical/x.ics'],
            'ftp'             => ['ftp://calendar.google.com/x.ics'],
            'file'            => ['file:///etc/passwd'],
            'no scheme'       => ['calendar.google.com/calendar/ical/x.ics'],
            'backslash'       => ['https://calendar.google.com\\@evil.com/'],
            'space'           => ['https://calendar.google.com/a b'],
            'escape above root' => ['https://calendar.google.com/../x'],
            'encoded slash'   => ['https://calendar.google.com/calendar/ical/..%2f..%2fx'],
            'encoded dot'     => ['https://calendar.google.com/calendar/ical/%2e%2e/x'],
        ];
    }

    #[DataProvider('rejectedUrls')]
    public function test_normalize_rejects_unfetchable_urls(string $url): void
    {
        $this->assertNull(UrlPolicy::normalize($url));
    }

    public function test_dot_segments_are_resolved_before_allowlist_matching(): void
    {
        $url = UrlPolicy::normalize('https://calendar.google.com/calendar/ical/../../evil');
        $this->assertSame('https://calendar.google.com/evil', $url);
        $this->assertFalse(UrlPolicy::allowed($url, ['https://calendar.google.com/calendar/ical/']));
    }

    public function test_allowlist_matches_on_exact_host_and_path_prefix(): void
    {
        $allow = ['https://calendar.google.com/calendar/ical/'];

        $this->assertTrue(UrlPolicy::allowed(self::GOOGLE, $allow));
        $this->assertFalse(UrlPolicy::allowed(UrlPolicy::normalize('https://calendar.google.com.evil.com/calendar/ical/x.ics'), $allow));
        $this->assertFalse(UrlPolicy::allowed(UrlPolicy::normalize('https://evil.com/calendar.google.com/calendar/ical/x.ics'), $allow));
        $this->assertFalse(UrlPolicy::allowed(UrlPolicy::normalize('https://calendar.google.com/calendar/icalx/x.ics'), $allow));
        $this->assertFalse(UrlPolicy::allowed(UrlPolicy::normalize('https://google.com/calendar/ical/x.ics'), $allow));
        $this->assertTrue(UrlPolicy::allowed(UrlPolicy::normalize('https://CALENDAR.GOOGLE.COM/calendar/ical/x.ics'), $allow));
    }

    public function test_a_prefix_without_trailing_slash_matches_only_at_a_segment_boundary(): void
    {
        $allow = ['https://example.org/feeds'];

        $this->assertTrue(UrlPolicy::allowed('https://example.org/feeds/a.ics', $allow));
        $this->assertTrue(UrlPolicy::allowed('https://example.org/feeds', $allow));
        $this->assertFalse(UrlPolicy::allowed('https://example.org/feeds-evil/a.ics', $allow));
    }

    public function test_null_allowlist_allows_anything(): void
    {
        $this->assertTrue(UrlPolicy::allowed('https://anything.example/', null));
    }

    public static function addresses(): array
    {
        return [
            ['8.8.8.8', true],
            ['142.250.80.46', true],
            ['2607:f8b0:4004:c07::64', true],
            ['127.0.0.1', false],
            ['10.1.2.3', false],
            ['172.16.0.1', false],
            ['192.168.1.1', false],
            ['169.254.169.254', false],
            ['100.64.0.1', false],
            ['0.0.0.0', false],
            ['224.0.0.1', false],
            ['198.18.0.1', false],
            ['::1', false],
            ['::', false],
            ['fc00::1', false],
            ['fd12:3456::1', false],
            ['fe80::1', false],
            ['::ffff:127.0.0.1', false],
            ['::ffff:10.0.0.1', false],
            ['::ffff:8.8.8.8', true],
            ['64:ff9b::7f00:1', false],
            ['2001:db8::1', false],
            ['not-an-ip', false],
        ];
    }

    #[DataProvider('addresses')]
    public function test_only_public_unicast_addresses_are_fetchable(string $ip, bool $public): void
    {
        $this->assertSame($public, UrlPolicy::isPublicIp($ip));
    }
}
