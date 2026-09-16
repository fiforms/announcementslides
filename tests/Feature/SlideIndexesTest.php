<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The slides table shipped with no indexes, and the ones added are load-
 * bearing rather than cosmetic: without them the per-entity board, the
 * archive and my-slides each scan the whole table. These assert the indexes
 * survive, since nothing else in the suite would notice them disappearing --
 * every query keeps returning correct results, just slowly.
 */
class SlideIndexesTest extends TestCase
{
    use RefreshDatabase;

    public static function expectedIndexes(): array
    {
        return [
            'visibility + workflow' => ['slides', ['entity_id', 'status', 'expires_at']],
            'slide ownership'       => ['slides', ['uploaded_by']],
            'show ordering'         => ['show_slides', ['show_id', 'sort_order']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('expectedIndexes')]
    public function test_the_index_exists(string $table, array $columns): void
    {
        $this->assertTrue(
            Schema::hasIndex($table, $columns),
            sprintf('Missing index on %s(%s) — queries filtering these columns fall back to a table scan.',
                $table, implode(', ', $columns))
        );
    }

    /**
     * uploaded_by is not decorative. With only the composite present, the
     * planner picks it for MySlideController's query and does *worse* than a
     * table scan, because `entity_id IS NULL` matches a large share of rows.
     */
    public function test_uploaded_by_is_indexed_separately_from_the_composite(): void
    {
        $this->assertTrue(
            Schema::hasIndex('slides', ['uploaded_by']),
            'uploaded_by needs its own index; the (entity_id, status, expires_at) composite '
            .'cannot serve an ownership lookup and the planner regresses if it tries.'
        );
    }
}
