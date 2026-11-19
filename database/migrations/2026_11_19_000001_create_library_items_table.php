<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The admin-managed slide library: shared backgrounds (image/video)
        // and overlay templates. Backgrounds are content-addressed (sha256),
        // so one file serves every slide built from it; see LibraryFiles.
        Schema::create('library_items', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20); // background | template
            $table->string('title');
            $table->string('sha256', 64)->nullable(); // backgrounds only
            $table->string('filename')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('disk', 30)->default('public');
            $table->string('disk_path')->nullable(); // template: null until first saved
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('thumbnail_path')->nullable();
            $table->string('poster_path')->nullable();
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();
            $table->json('overlay_settings')->nullable(); // template widget placements
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kind', 'created_at']);
            $table->unique(['kind', 'sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_items');
    }
};
