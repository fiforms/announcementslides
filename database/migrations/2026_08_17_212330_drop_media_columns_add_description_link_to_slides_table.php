<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slides', function (Blueprint $table) {
            $table->text('text_description')->nullable()->after('notes');
            $table->string('link')->nullable()->after('text_description');
        });

        Schema::table('slides', function (Blueprint $table) {
            $table->dropColumn([
                'filename', 'original_filename', 'disk_path', 'file_size', 'mime_type',
                'thumbnail_path', 'image_width', 'image_height', 'validation_issues', 'validation_status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('slides', function (Blueprint $table) {
            $table->string('filename')->nullable()->after('link');
            $table->string('original_filename')->nullable()->after('filename');
            $table->string('disk_path')->nullable()->after('original_filename');
            $table->unsignedBigInteger('file_size')->nullable()->after('disk_path');
            $table->string('mime_type')->nullable()->after('file_size');
            $table->string('thumbnail_path')->nullable()->after('mime_type');
            $table->unsignedInteger('image_width')->nullable()->after('thumbnail_path');
            $table->unsignedInteger('image_height')->nullable()->after('image_width');
            $table->json('validation_issues')->nullable()->after('image_height');
            $table->enum('validation_status', ['ok', 'warning', 'error'])->default('ok')->after('validation_issues');
        });

        Schema::table('slides', function (Blueprint $table) {
            $table->dropColumn(['text_description', 'link']);
        });
    }
};
