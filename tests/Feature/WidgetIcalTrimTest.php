<?php

namespace Tests\Feature;

use App\Services\Widgets\WidgetDataService;
use Carbon\CarbonImmutable;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The ICS trim must never change what parseIcal returns — it only exists to
 * keep years of past events from being parsed. Each case here is one way it
 * could go wrong.
 */
class WidgetIcalTrimTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // "Now" is Thu 8 Oct 2026, so the window is 7 Oct – 6 Dec.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 10:00', 'America/New_York'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function event(string ...$lines): string
    {
        return "BEGIN:VEVENT\r\n" . implode("\r\n", $lines) . "\r\nEND:VEVENT\r\n";
    }

    private function calendar(string ...$events): string
    {
        $zone = "BEGIN:VTIMEZONE\r\nTZID:America/New_York\r\n"
            . "BEGIN:STANDARD\r\nDTSTART:19701101T020000\r\nTZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\nRRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU\r\nEND:STANDARD\r\n"
            . "BEGIN:DAYLIGHT\r\nDTSTART:19700308T020000\r\nTZOFFSETFROM:-0500\r\nTZOFFSETTO:-0400\r\nRRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU\r\nEND:DAYLIGHT\r\n"
            . "END:VTIMEZONE\r\n";

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\nX-WR-CALNAME:Test\r\n" . $zone . implode('', $events) . "END:VCALENDAR\r\n";
    }

    private function titles(string $ics): array
    {
        $method = new ReflectionMethod(WidgetDataService::class, 'parseIcal');
        $method->setAccessible(true);
        $events = $method->invoke(app(WidgetDataService::class), $ics, 60)['events'];

        return collect($events)->map(fn ($e) => $e['title'] . '@' . substr($e['start'], 0, 10))->sort()->values()->all();
    }

    private function trimmed(string $ics): string
    {
        $method = new ReflectionMethod(WidgetDataService::class, 'trimIcal');
        $method->setAccessible(true);
        $now = CarbonImmutable::now();

        return $method->invoke(app(WidgetDataService::class), $ics, $now->subDay(), $now->addDays(60));
    }

    public function test_drops_events_outside_the_window_but_keeps_the_ones_that_matter(): void
    {
        $ics = $this->calendar(
            $this->event('UID:old', 'SUMMARY:Long ago', 'DTSTART;VALUE=DATE:20230105', 'DTEND;VALUE=DATE:20230106'),
            $this->event('UID:far', 'SUMMARY:Far future', 'DTSTART;VALUE=DATE:20290105', 'DTEND;VALUE=DATE:20290106'),
            $this->event('UID:now', 'SUMMARY:Soon', 'DTSTART;TZID=America/New_York:20261020T190000', 'DTEND;TZID=America/New_York:20261020T200000'),
            $this->event('UID:span', 'SUMMARY:Started earlier', 'DTSTART;VALUE=DATE:20261001', 'DTEND;VALUE=DATE:20261010'),
            $this->event('UID:dur', 'SUMMARY:By duration', 'DTSTART:20260925T120000Z', 'DURATION:P30D'),
        );

        $trimmed = $this->trimmed($ics);
        $this->assertStringNotContainsString('Long ago', $trimmed);
        $this->assertStringNotContainsString('Far future', $trimmed);
        $this->assertStringContainsString('VTIMEZONE', $trimmed);
        $this->assertSame(
            ['By duration@2026-09-25', 'Soon@2026-10-20', 'Started earlier@2026-10-01'],
            $this->titles($ics),
        );
    }

    public function test_recurring_events_still_expand_into_the_window(): void
    {
        $ics = $this->calendar($this->event(
            'UID:weekly', 'SUMMARY:Prayer', 'DTSTART;TZID=America/New_York:20200106T190000', 'DTEND;TZID=America/New_York:20200106T200000',
            'RRULE:FREQ=WEEKLY;BYDAY=MO',
        ));

        $titles = $this->titles($ics);
        $this->assertContains('Prayer@2026-10-12', $titles);
        $this->assertContains('Prayer@2026-10-19', $titles);
        $this->assertGreaterThanOrEqual(8, count($titles));
    }

    public function test_an_instance_moved_out_of_the_window_does_not_reappear(): void
    {
        $master = $this->event(
            'UID:series', 'SUMMARY:Council', 'DTSTART;TZID=America/New_York:20260105T100000', 'DTEND;TZID=America/New_York:20260105T110000',
            'RRULE:FREQ=WEEKLY;BYDAY=MO;UNTIL=20261231T000000Z',
        );
        // The 19 Oct instance was moved to next year: its new date is far outside the window.
        $moved = $this->event(
            'UID:series', 'SUMMARY:Council', 'RECURRENCE-ID;TZID=America/New_York:20261019T100000',
            'DTSTART;TZID=America/New_York:20270301T100000', 'DTEND;TZID=America/New_York:20270301T110000',
        );

        $this->assertStringContainsString('RECURRENCE-ID', $this->trimmed($this->calendar($master, $moved)));
        $titles = $this->titles($this->calendar($master, $moved));
        $this->assertNotContains('Council@2026-10-19', $titles);
        $this->assertContains('Council@2026-10-12', $titles);
    }

    public function test_an_instance_moved_into_the_window_from_outside_appears(): void
    {
        $master = $this->event(
            'UID:series', 'SUMMARY:Council', 'DTSTART;TZID=America/New_York:20260105T100000', 'DTEND;TZID=America/New_York:20260105T110000',
            'RRULE:FREQ=MONTHLY;BYMONTHDAY=5',
        );
        // The 5 March instance was moved to 20 Oct.
        $moved = $this->event(
            'UID:series', 'SUMMARY:Council moved', 'RECURRENCE-ID;TZID=America/New_York:20260305T100000',
            'DTSTART;TZID=America/New_York:20261020T100000', 'DTEND;TZID=America/New_York:20261020T110000',
        );

        $this->assertContains('Council moved@2026-10-20', $this->titles($this->calendar($master, $moved)));
    }

    public function test_a_feed_with_no_events_or_odd_layout_is_left_alone(): void
    {
        $empty = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";
        $this->assertSame($empty, $this->trimmed($empty));

        // Folded lines and LF-only endings.
        $ics = str_replace("\r\n", "\n", $this->calendar(
            $this->event('UID:a', 'SUMMARY:Folded title that is', ' continued', 'DTSTART;VALUE=DATE:20261020', 'DTEND;VALUE=DATE:20261021'),
        ));
        $this->assertSame(['Folded title that iscontinued@2026-10-20'], $this->titles($ics));
    }
}
