<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('slides', function (Blueprint $table) {
            $table->unsignedInteger('image_width')->nullable()->after('mime_type');
            $table->unsignedInteger('image_height')->nullable()->after('image_width');
            $table->json('validation_issues')->nullable()->after('image_height');
            $table->enum('validation_status', ['ok', 'warning', 'error'])->default('ok')->after('validation_issues');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('slides', function (Blueprint $table) {
            $table->dropColumn(['image_width', 'image_height', 'validation_issues', 'validation_status']);
        });
    }
};
