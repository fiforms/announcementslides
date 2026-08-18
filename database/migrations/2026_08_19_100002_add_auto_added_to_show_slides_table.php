<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('show_slides', function (Blueprint $table) {
            $table->boolean('auto_added')->default(false)->after('sort_order');
        });

        // Best-effort backfill for links created before this column existed:
        // a link where the slide's own entity differs from the show's entity
        // (i.e. the slide is global, or borrowed from a nearby entity) can
        // only have gotten there via the global/nearby fan-out, so it's
        // "auto-added" for reconciliation purposes. A link where the slide
        // belongs to the same entity as the show is the entity's own content
        // (uploaded directly or manually placed), so it's left as a "manual"
        // (auto_added = false) keep that language reconciliation won't touch.
        DB::statement("
            UPDATE show_slides
            SET auto_added = true
            WHERE slide_id IN (
                SELECT slides.id FROM slides
                JOIN shows ON shows.id = show_slides.show_id
                WHERE slides.entity_id IS NULL OR slides.entity_id != shows.entity_id
            )
        ");
    }

    public function down(): void
    {
        Schema::table('show_slides', function (Blueprint $table) {
            $table->dropColumn('auto_added');
        });
    }
};
