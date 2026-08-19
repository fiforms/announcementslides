<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * The five fixed ranges a show's `show_slides.sort_order` values fall into,
 * alternating leader-assigned zones with automatic fan-out zones so a
 * slide's purpose always occupies the same region of the order:
 *
 *   [0, R-1]     leader_early  — manual
 *   [R, 2R-1]    global        — automatic (decrementing counter)
 *   [2R, 3R-1]   leader_mid    — manual
 *   [3R, 4R-1]   nearby        — automatic (decrementing counter)
 *   [4R, 5R-1]   leader_late   — manual
 *
 * See Slide::assignFanoutSortOrderIfNeeded() for how the automatic zones get
 * their values, and Show::reconcilePair()/ShowController for how the manual
 * zones get theirs.
 */
class SortZones
{
    public const RANGE_SIZE = 2 ** 24; // 16,777,216

    public const LEADER_EARLY = 'leader_early';
    public const GLOBAL = 'global';
    public const LEADER_MID = 'leader_mid';
    public const NEARBY = 'nearby';
    public const LEADER_LATE = 'leader_late';

    private const ORDER = [
        self::LEADER_EARLY,
        self::GLOBAL,
        self::LEADER_MID,
        self::NEARBY,
        self::LEADER_LATE,
    ];

    /**
     * @return array{0: int, 1: int} [start, end] inclusive
     */
    public static function bounds(string $zone): array
    {
        $index = array_search($zone, self::ORDER, true);
        if ($index === false) {
            throw new InvalidArgumentException("Unknown sort zone: {$zone}");
        }

        $start = $index * self::RANGE_SIZE;

        return [$start, $start + self::RANGE_SIZE - 1];
    }

    public static function zoneFor(int $sortOrder): string
    {
        $index = intdiv(max($sortOrder, 0), self::RANGE_SIZE);

        return self::ORDER[min($index, count(self::ORDER) - 1)];
    }

    /**
     * @return string[]
     */
    public static function leaderZones(): array
    {
        return [self::LEADER_EARLY, self::LEADER_MID, self::LEADER_LATE];
    }
}
