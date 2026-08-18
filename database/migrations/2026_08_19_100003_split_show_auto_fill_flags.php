<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->renameColumn('auto_fill', 'auto_fill_global');
        });

        Schema::table('shows', function (Blueprint $table) {
            $table->boolean('auto_fill_nearby')->default(false)->after('auto_fill_global');
        });

        // Nearby content used to flow into every Main show by default (via
        // the now-retired entities.auto_add_nearby_slides, which nobody had
        // a way to turn off); preserve that default going forward as the
        // per-show flag instead.
        DB::table('shows')->where('is_main', true)->whereNotNull('entity_id')
            ->update(['auto_fill_nearby' => true]);
    }

    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropColumn('auto_fill_nearby');
        });

        Schema::table('shows', function (Blueprint $table) {
            $table->renameColumn('auto_fill_global', 'auto_fill');
        });
    }
};
