<?php

use App\Support\SortZones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time reclassification of existing show_slides rows into the new
 * SortZones scheme (see App\Support\SortZones, Slide::assignFanoutSortOrderIfNeeded).
 *
 * - Every auto_added = true row was placed by the global/nearby fan-out.
 *   Each distinct slide behind those rows gets a fanout_sort_order, assigned
 *   in ascending order of its current minimum pivot sort_order (so today's
 *   relative order survives), then every one of its pivot rows is rewritten
 *   to that value.
 * - Every auto_added = false row per show is a leader's manual placement;
 *   it's renumbered into the leader_early zone, preserving its current
 *   relative order within that show.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assignFanoutOrders('global', fn ($q) => $q->whereNull('slides.entity_id'));
        $this->assignFanoutOrders('nearby', fn ($q) => $q->whereNotNull('slides.entity_id'));

        DB::statement('
            UPDATE show_slides
            SET sort_order = (SELECT fanout_sort_order FROM slides WHERE slides.id = show_slides.slide_id)
            WHERE auto_added = true
        ');

        [$leaderEarlyStart] = SortZones::bounds(SortZones::LEADER_EARLY);

        DB::table('shows')->pluck('id')->each(function ($showId) use ($leaderEarlyStart) {
            $rows = DB::table('show_slides')
                ->where('show_id', $showId)
                ->where('auto_added', false)
                ->orderBy('sort_order')
                ->get(['id']);

            foreach ($rows as $index => $row) {
                DB::table('show_slides')->where('id', $row->id)->update([
                    'sort_order' => $leaderEarlyStart + $index,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Not reversible: the pre-migration sort_order values aren't preserved.
    }

    /**
     * Assign a fanout_sort_order to every distinct slide reached by an
     * auto_added = true show_slides row and matching $filter (global or
     * nearby), decrementing the matching sort_counters row as it goes, in
     * ascending order of the slide's current minimum pivot sort_order.
     */
    private function assignFanoutOrders(string $counterKey, callable $filter): void
    {
        $slides = $filter(
            DB::table('show_slides')
                ->join('slides', 'slides.id', '=', 'show_slides.slide_id')
                ->where('show_slides.auto_added', true)
                ->whereNull('slides.fanout_sort_order')
        )
            ->groupBy('slides.id')
            ->orderBy(DB::raw('MIN(show_slides.sort_order)'))
            ->pluck('slides.id');

        if ($slides->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($counterKey, $slides) {
            $counter = DB::table('sort_counters')->where('key', $counterKey)->lockForUpdate()->first();
            $value = $counter->value;

            foreach ($slides as $slideId) {
                $value--;
                DB::table('slides')->where('id', $slideId)->update(['fanout_sort_order' => $value]);
            }

            DB::table('sort_counters')->where('key', $counterKey)->update(['value' => $value]);
        });
    }
};
