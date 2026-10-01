<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI upscaling keeps the file as it was uploaded alongside the upscaled
     * one. The row's own file columns always describe the *active* version
     * (so everything that reads slide_media keeps working untouched);
     * `variants` holds the file snapshots of both versions, keyed 'original'
     * and 'upscaled', and `active_variant` says which one the row currently
     * mirrors. Both stay null for media that was never upscaled.
     */
    public function up(): void
    {
        Schema::table('slide_media', function (Blueprint $table) {
            $table->json('variants')->nullable();
            $table->string('active_variant', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('slide_media', function (Blueprint $table) {
            $table->dropColumn(['variants', 'active_variant']);
        });
    }
};
