<?php

namespace App\Console\Commands;

use App\Jobs\GenerateThumbnail;
use App\Jobs\SyncOverlayThumbnail;
use App\Models\Slide;
use App\Models\SlideMedia;
use Illuminate\Console\Command;

class SlideResyncThumbnails extends Command
{
    protected $signature = 'slide:resync-thumbnails
                            {--all : Also rebuild slides that have an overlay but no stored composite}
                            {--now : Run the rebuilds immediately instead of queueing them}
                            {--posters : Also store a full-size poster frame (and rebuild the thumbnail) for every video that has none}';

    protected $description = 'Rebuild slide+overlay composite thumbnails (fixes stale "ghost" previews)';

    public function handle(): int
    {
        if ($this->option('posters')) {
            $videos = SlideMedia::where('mime_type', 'like', 'video/%')->whereNull('poster_path')->get();
            foreach ($videos as $media) {
                $this->option('now') ? GenerateThumbnail::dispatchSync($media) : GenerateThumbnail::dispatch($media);
            }
            $this->info(($this->option('now') ? 'Built ' : 'Queued ') . "{$videos->count()} video poster(s).");
        }

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
