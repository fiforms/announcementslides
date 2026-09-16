<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The slides table shipped with no indexes at all -- not even on entity_id,
 * which nearly every query filters on via visibleToUser()/entityScoped().
 * Every other table in the schema gets deliberate composite indexes
 * (slide_media, show_slides, heartbeats, releases); the hottest one was
 * simply skipped.
 *
 * The three added here were chosen by measuring, not by listing filter
 * columns -- a naive set made one query slower. Benchmarked over 64k slides
 * across 300 entities, 200 uploaders (SQLite, 200 runs per query):
 *
 *   entity board   6.2 ms -> 0.11 ms
 *   my-slides      5.2 ms -> 0.16 ms
 *   archive        8.1 ms -> 2.4 ms
 *   global board   0.9 ms -> 0.9 ms   (already fast; see below)
 *
 * Notes on the choices:
 *
 * - (entity_id, status, expires_at) leads with entity_id because that is the
 *   column every visibility query touches. The trailing columns are what
 *   brings archive() down; stopping at (entity_id, status) left archive
 *   unchanged at 7.7 ms.
 *
 * - uploaded_by is not optional. With only the composite present the planner
 *   chose it for MySlideController's query and regressed that query to
 *   9.1 ms -- worse than the 5.2 ms table scan -- because `entity_id IS NULL`
 *   matches around a quarter of rows. Adding uploaded_by gives the planner a
 *   selective alternative and the query drops to 0.16 ms.
 *
 * - show_slides (show_id, sort_order) removes the "USE TEMP B-TREE FOR ORDER
 *   BY" step from the board query, which orders by that column. No
 *   wall-clock gain at this size -- the board query is already served by the
 *   existing (show_id, slide_id) unique index and was never the problem --
 *   but it turns a sort into an ordered index read as shows grow.
 *
 * Deliberately not added: a status/expires_at pair on its own (no measurable
 * effect, since status='published' matches ~88% of rows), and deleted_at
 * (SoftDeletes' `IS NULL` is likewise unselective).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slides', function (Blueprint $table) {
            $table->index(['entity_id', 'status', 'expires_at']);
            $table->index('uploaded_by');
        });

        Schema::table('show_slides', function (Blueprint $table) {
            $table->index(['show_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('slides', function (Blueprint $table) {
            $table->dropIndex(['entity_id', 'status', 'expires_at']);
            $table->dropIndex(['uploaded_by']);
        });

        Schema::table('show_slides', function (Blueprint $table) {
            $table->dropIndex(['show_id', 'sort_order']);
        });
    }
};
