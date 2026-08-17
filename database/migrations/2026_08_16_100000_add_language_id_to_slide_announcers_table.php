<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Entity-admin-assigned language for this device, mirroring
     * Slide::language_id — null means no language assigned yet, in which
     * case the device falls back to its own boot-yaml default (see
     * slideannouncer/LOCALIZATION_TODO.md).
     */
    public function up(): void
    {
        Schema::table('slide_announcers', function (Blueprint $table) {
            $table->foreignId('language_id')->nullable()->after('entity_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('slide_announcers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('language_id');
        });
    }
};
