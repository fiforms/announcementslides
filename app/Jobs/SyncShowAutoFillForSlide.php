<?php

namespace App\Jobs;

use App\Models\Show;
use App\Models\Slide;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fans a global or nearby-shared slide out to every eligible entity's
 * auto_fill shows (language-gated) — see Show::syncAutoFillForSlide().
 * Queued since a global slide's eligible set is every entity in the system.
 */
class SyncShowAutoFillForSlide implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $slideId)
    {
    }

    public function handle(): void
    {
        $slide = Slide::find($this->slideId);
        if ($slide) {
            Show::syncAutoFillForSlide($slide);
        }
    }
}
