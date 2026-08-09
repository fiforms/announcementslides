<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A rolling per-device log, one row per heartbeat ping. Pruned by age via
     * the slide-announcer:prune-heartbeats artisan command, not query scopes
     * (unlike Slide's expiry rules) — this is a log, not user-facing content.
     */
    public function up(): void
    {
        Schema::create('slide_announcer_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slide_announcer_id')->constrained()->cascadeOnDelete();
            $table->string('app_version')->nullable();
            $table->string('os_version')->nullable();
            $table->string('ip_address')->nullable();
            $table->float('cpu_temp_c')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['slide_announcer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slide_announcer_heartbeats');
    }
};
