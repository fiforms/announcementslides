<?php

namespace App\Jobs;

use App\Models\Entity;
use App\Models\GlobalShowTemplate;
use App\Models\Show;
use App\Models\Slide;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fans a global "separate show" upload (e.g. a special sound-on promo video
 * every site should get its own copy of) out into every entity as its own
 * Show row, tagged auto_delete_when_empty so shows:prune-empty can sweep
 * every entity's copy once the underlying slide(s) expire.
 */
class DistributeGlobalSeparateShow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $globalTemplateId,
        private readonly int $slideId,
    ) {
    }

    public function handle(): void
    {
        $template = GlobalShowTemplate::find($this->globalTemplateId);
        $slide = Slide::find($this->slideId);
        if (!$template || !$slide) {
            return;
        }

        Entity::query()->orderBy('id')->chunkById(200, function ($entities) use ($template, $slide) {
            foreach ($entities as $entity) {
                $show = Show::firstOrCreate(
                    ['entity_id' => $entity->id, 'global_template_id' => $template->id],
                    ['name' => $template->name, 'is_main' => false, 'auto_delete_when_empty' => true]
                );

                $sortOrder = $slide->assignFanoutSortOrderIfNeeded();
                $show->slides()->syncWithoutDetaching([$slide->id => ['sort_order' => $sortOrder, 'auto_added' => true]]);
            }
        });
    }
}
