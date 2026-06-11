<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slides', function (Blueprint $table) {
            $table->foreignId('language_id')->nullable()->constrained('languages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('slides', function (Blueprint $table) {
            $table->dropForeignKeyIfExists(['language_id']);
            $table->dropColumn('language_id');
        });
    }
};
