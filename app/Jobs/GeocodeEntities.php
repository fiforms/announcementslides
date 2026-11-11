<?php

namespace App\Jobs;

use App\Services\DirectoryImport\EntityGeocoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Runs the Census lookup in the background (a few hundred addresses can take
 * longer than a web request may run) and leaves a status record in the cache
 * for the Directory Import page.
 */
class GeocodeEntities implements ShouldQueue
{
    use Queueable;

    public const CACHE_KEY = 'directory_import.geocode';

    public int $timeout = 900;

    public int $tries = 1;

    public static function status(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    /** A "running" record older than 30 minutes is assumed dead (e.g. the worker was killed). */
    public static function isRunning(): bool
    {
        $s = self::status();

        return ($s['state'] ?? null) === 'running'
            && now()->diffInMinutes($s['started_at'] ?? now()) < 30;
    }

    public static function markQueued(): void
    {
        Cache::put(self::CACHE_KEY, ['state' => 'running', 'started_at' => now()->toIso8601String()], now()->addDay());
    }

    public function handle(EntityGeocoder $geocoder): void
    {
        $started = self::status()['started_at'] ?? now()->toIso8601String();

        $result = $geocoder->geocode();

        Cache::put(self::CACHE_KEY, [
            'state'       => 'done',
            'started_at'  => $started,
            'finished_at' => now()->toIso8601String(),
            'matched'     => $result['matched'],
            'unmatched'   => $result['unmatched'],
            'unmatched_names' => array_slice($result['unmatched_names'], 0, 50),
        ], now()->addDays(7));
    }

    public function failed(Throwable $e): void
    {
        Cache::put(self::CACHE_KEY, [
            'state'       => 'failed',
            'finished_at' => now()->toIso8601String(),
            'error'       => $e->getMessage(),
        ], now()->addDays(7));
    }
}
