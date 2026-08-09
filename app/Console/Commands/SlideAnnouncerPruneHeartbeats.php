<?php

namespace App\Console\Commands;

use App\Models\SlideAnnouncerHeartbeat;
use Illuminate\Console\Command;

class SlideAnnouncerPruneHeartbeats extends Command
{
    protected $signature = 'slide-announcer:prune-heartbeats
                            {--days= : Override the configured retention window}';

    protected $description = 'Delete slide_announcer_heartbeats rows older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('slide_announcer.heartbeat_retention_days'));

        $cutoff = now()->subDays($days);
        $deleted = SlideAnnouncerHeartbeat::where('created_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} heartbeat row(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
