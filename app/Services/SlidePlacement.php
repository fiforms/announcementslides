<?php

namespace App\Services;

use App\Http\Controllers\ShowController;
use App\Jobs\AnnounceSlideAvailability;
use App\Jobs\DistributeGlobalSeparateShow;
use App\Jobs\SyncShowAutoFillForSlide;
use App\Models\Entity;
use App\Models\GlobalShowTemplate;
use App\Models\Show;
use App\Models\Slide;
use App\Support\SortZones;
use Illuminate\Http\Request;

/**
 * Puts newly created slides into shows, from the same request fields every
 * creation path uses (`add_to_show`, `show_id`, `new_show_name`,
 * `global_template_id`, `promo_token`): the uploader and the library builder.
 */
class SlidePlacement
{
    /**
     * Apply the "add to show" upload choice. Local uploads (entity_id set)
     * choose among that entity's own Main/other shows — always a direct,
     * manual placement (never touched by later language reconciliation).
     * Global uploads (entity_id null) choose between the Global Board (whose
     * slides then fan out to every entity's auto_fill shows whose language
     * matches, or which accept any language — see Show::syncAutoFillForSlide)
     * and a globally-distributed one-off show (unconditionally fanned out to
     * every entity as its own show, regardless of language).
     *
     * @param  Slide[]  $slides
     */
    public function route(Request $request, array $slides, ?int $entityId): void
    {
        if (empty($slides)) {
            return;
        }

        $addToShow = $request->input('add_to_show', 'main');

        if ($entityId !== null) {
            $entity = Entity::findOrFail($entityId);

            $show = match ($addToShow) {
                'main' => $entity->mainShow(),
                'separate' => $request->show_id
                    ? Show::where('entity_id', $entityId)->findOrFail($request->show_id)
                    : Show::create([
                        'entity_id' => $entityId,
                        'name' => $request->new_show_name,
                        'is_main' => false,
                        'created_by' => $request->user()->id,
                    ]),
                default => null,
            };

            if ($show) {
                [, $end] = SortZones::bounds(SortZones::LEADER_LATE);
                $nextOrder = ShowController::nextSortOrderInZone($show, SortZones::LEADER_LATE);
                foreach ($slides as $i => $slide) {
                    $show->slides()->syncWithoutDetaching([
                        $slide->id => ['sort_order' => min($nextOrder + $i, $end), 'auto_added' => false],
                    ]);
                }
            }

            // Shared with nearby churches at upload: fan it out to their
            // auto_fill_nearby shows exactly like the "Share nearby" button
            // does, then tell their managers (the job does both, queued).
            foreach ($slides as $slide) {
                if ($slide->share_nearby) {
                    SyncShowAutoFillForSlide::dispatch($slide->id);
                }
            }

            return;
        }

        // Global upload.
        $globalBoard = Show::globalBoard();

        if ($addToShow === 'separate') {
            $template = $request->global_template_id
                ? GlobalShowTemplate::findOrFail($request->global_template_id)
                : GlobalShowTemplate::create([
                    'token' => filled($request->promo_token) ? $request->promo_token : null,
                    'name' => $request->new_show_name,
                    'created_by' => $request->user()->id,
                ]);

            foreach ($slides as $slide) {
                DistributeGlobalSeparateShow::dispatch($template->id, $slide->id);
            }

            return;
        }

        // 'main' (default) and 'none' both land on the Global Board — 'none'
        // just skips the fan-out to every entity's Main show below. The
        // Global Board's own order is automatic too: every global slide gets
        // the same shared fanout counter value it'll carry into every other
        // show, so it always bubbles to the same relative spot everywhere.
        foreach ($slides as $slide) {
            $sortOrder = $slide->assignFanoutSortOrderIfNeeded();
            $globalBoard->slides()->syncWithoutDetaching([$slide->id => ['sort_order' => $sortOrder, 'auto_added' => true]]);

            if ($addToShow !== 'none') {
                SyncShowAutoFillForSlide::dispatch($slide->id);
            } else {
                // Not added anywhere, but churches still hear it's available.
                AnnounceSlideAvailability::dispatch($slide->id);
            }
        }
    }
}
