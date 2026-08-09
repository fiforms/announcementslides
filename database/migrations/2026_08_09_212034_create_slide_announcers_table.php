<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slide_announcers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Informational hardware inventory, separate from device_uuid (the
            // device-generated, anti-clone-checked identifier) — see SLIDE_ANNOUNCER.md.
            $table->string('mac_address')->nullable();
            $table->string('device_uuid')->nullable()->unique();
            $table->string('app_version')->nullable();
            $table->string('os_version')->nullable();
            $table->enum('update_channel', ['stable', 'testing', 'developer'])->default('stable');
            $table->boolean('auto_update_enabled')->default(true);
            // Free-form per-device display settings (slide duration, transition, ...).
            $table->json('settings')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip')->nullable();
            $table->float('last_cpu_temp_c')->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->foreignId('paired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slide_announcers');
    }
};
