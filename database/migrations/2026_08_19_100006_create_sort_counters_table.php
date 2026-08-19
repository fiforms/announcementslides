<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\SortZones;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sort_counters', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->unsignedInteger('value');
        });

        // Each counter starts at the top of its zone and decrements on every
        // slide that newly becomes eligible for that kind of fan-out, so new
        // slides always sort first within their zone. See SortZones and
        // Slide::assignFanoutSortOrderIfNeeded().
        [, $globalTop] = SortZones::bounds(SortZones::GLOBAL);
        [, $nearbyTop] = SortZones::bounds(SortZones::NEARBY);

        DB::table('sort_counters')->insert([
            ['key' => 'global', 'value' => $globalTop],
            ['key' => 'nearby', 'value' => $nearbyTop],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sort_counters');
    }
};
