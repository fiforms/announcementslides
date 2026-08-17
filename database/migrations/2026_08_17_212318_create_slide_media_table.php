<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per file attached to a Slide. media_type is a plain string
     * (not an enum) so new types (see config/slides.php: media_types) can be
     * added without a migration. overlay_settings is reserved for a future
     * feature: a 'slide-overlay' row composites on top of the 'slide' row of
     * the same slide_id; the column is unused until that's built.
     */
    public function up(): void
    {
        Schema::create('slide_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slide_id')->constrained()->cascadeOnDelete();
            $table->string('media_type');
            $table->string('filename');
            $table->string('original_filename');
            $table->string('disk_path');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type');
            $table->string('thumbnail_path')->nullable();
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();
            $table->json('validation_issues')->nullable();
            $table->enum('validation_status', ['ok', 'warning', 'error'])->default('ok');
            $table->json('overlay_settings')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['slide_id', 'media_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slide_media');
    }
};
