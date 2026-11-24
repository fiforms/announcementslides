<?php

namespace App\Jobs;

use App\Models\Slide;
use App\Notifications\SlideAvailabilityNotifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends a slide's "new slide available" notices off the request, for paths
 * that don't already run in SyncShowAutoFillForSlide (a global upload set
 * to "don't add to shows", a slide shared with nearby churches).
 */
class AnnounceSlideAvailability implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @param array<int> $attachedShowIds */
    public function __construct(
        public readonly int $slideId,
        public readonly array $attachedShowIds = [],
    ) {}

    public function handle(): void
    {
        if ($slide = Slide::find($this->slideId)) {
            SlideAvailabilityNotifications::announce($slide, $this->attachedShowIds);
        }
    }
}
