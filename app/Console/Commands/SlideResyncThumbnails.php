<?php

namespace App\Console\Commands;

use App\Jobs\SyncOverlayThumbnail;
use App\Models\Slide;
use Illuminate\Console\Command;

class SlideResyncThumbnails extends Command
{
    protected $signature = 'slide:resync-thumbnails
                            {--all : Also rebuild slides that have an overlay but no stored composite}
                            {--now : Run the rebuilds immediately instead of queueing them}';

    protected $description = 'Rebuild slide+overlay composite thumbnails (fixes stale "ghost" previews)';

    public function handle(): int
    {
        $query = Slide::query()->where(function ($q) {
            $q->whereNotNull('overlay_thumbnail_path');
            if ($this->option('all')) {
                $q->orWhereHas('overlayMedia');
            }
        });

        $count = 0;
        $query->pluck('id')->each(function ($id) use (&$count) {
            $this->option('now')
                ? SyncOverlayThumbnail::dispatchSync($id)
                : SyncOverlayThumbnail::dispatch($id);
            $count++;
        });

        $this->info(($this->option('now') ? 'Rebuilt ' : 'Queued ') . "{$count} slide thumbnail(s).");

        return self::SUCCESS;
    }
}
