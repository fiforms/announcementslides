<?php

use App\Support\NearbyEntities;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('shows')->where('entity_id', null)->where('is_main', true)->exists()) {
            // Already backfilled — safe to skip on re-run.
            return;
        }

        $now = now();

        // 1. Global Board — seeds master ordering for global slides.
        $globalBoardId = DB::table('shows')->insertGetId([
            'entity_id' => null,
            'name' => 'Global Board',
            'is_main' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $globalSlideIds = DB::table('slides')
            ->whereNull('entity_id')
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->pluck('id')
            ->values();

        $this->insertShowSlides($globalBoardId, $globalSlideIds);

        // 2. Each entity's Main show, seeded from its own slides, then the
        // Global Board's slides, then (if enabled) nearby-shared slides.
        \App\Models\Entity::query()->orderBy('id')
            ->chunk(200, function ($entities) use ($now, $globalSlideIds) {
                foreach ($entities as $entity) {
                    $mainShowId = DB::table('shows')->insertGetId([
                        'entity_id' => $entity->id,
                        'name' => 'Main Show',
                        'is_main' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $ownSlideIds = DB::table('slides')
                        ->where('entity_id', $entity->id)
                        ->whereNull('deleted_at')
                        ->orderBy('sort_order')
                        ->orderByDesc('created_at')
                        ->pluck('id')
                        ->values();

                    $orderedIds = $ownSlideIds->concat($globalSlideIds);

                    if ($entity->auto_add_nearby_slides && $entity->latitude !== null && $entity->longitude !== null) {
                        $radius = (float) config('slides.nearby_radius_miles');
                        $nearbyIds = NearbyEntities::within($entity, $radius);

                        if (!empty($nearbyIds)) {
                            $nearbySlideIds = DB::table('slides')
                                ->whereIn('entity_id', $nearbyIds)
                                ->where('share_nearby', true)
                                ->whereNull('deleted_at')
                                ->where('status', 'published')
                                ->orderBy('sort_order')
                                ->orderByDesc('created_at')
                                ->pluck('id')
                                ->values();

                            $orderedIds = $orderedIds->concat($nearbySlideIds);
                        }
                    }

                    $this->insertShowSlides($mainShowId, $orderedIds);
                }
            });
    }

    private function insertShowSlides(int $showId, $slideIds): void
    {
        $now = now();
        $rows = $slideIds->values()->map(fn ($slideId, $i) => [
            'show_id' => $showId,
            'slide_id' => $slideId,
            'sort_order' => $i,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows) {
            DB::table('show_slides')->insert($rows);
        }
    }

    public function down(): void
    {
        DB::table('show_slides')->truncate();
        DB::table('shows')->truncate();
    }
};
