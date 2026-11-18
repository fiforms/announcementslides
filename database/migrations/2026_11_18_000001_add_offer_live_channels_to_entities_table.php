<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether this church's Slide Announcers offer the live channel catalog
     * (Admin > Live Channels) in their remote menu, under "Live Channels".
     * On by default; a church that never wants live TV on its screens turns
     * it off on its Slide Announcers page.
     */
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->boolean('offer_live_channels')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn('offer_live_channels');
        });
    }
};
