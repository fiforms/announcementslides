<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Superseded by the per-show auto_fill_nearby flag, which gives
        // leaders the same control at finer granularity — this entity-wide
        // switch never had a UI built for it.
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn('auto_add_nearby_slides');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->boolean('auto_add_nearby_slides')->default(true)->after('longitude');
        });
    }
};
