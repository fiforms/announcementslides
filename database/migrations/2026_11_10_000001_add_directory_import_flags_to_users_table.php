<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Account was created by the pastor directory import.
            $table->boolean('created_by_import')->default(false);
            // Listed in the most recent pastor import (reset on every import).
            $table->boolean('in_latest_import')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['created_by_import', 'in_latest_import']);
        });
    }
};
