<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-installed overlay widgets (see App\Services\Widgets). One row per
     * widget slug; installing a new version replaces the row's version,
     * manifest and file list in place, so slides (which reference widgets by
     * slug in slide_media.overlay_settings) pick up upgrades automatically.
     * `settings` holds admin-entered values the manifest declares (API keys,
     * stored encrypted); `extra_allow` holds extra URL-prefix allowlist
     * entries per url parameter, added without re-packaging the widget.
     */
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->string('version', 64);
            $table->text('description')->nullable();
            $table->json('manifest');
            $table->json('files');
            $table->boolean('enabled')->default(true);
            $table->text('settings')->nullable();
            $table->json('extra_allow')->nullable();
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widgets');
    }
};
