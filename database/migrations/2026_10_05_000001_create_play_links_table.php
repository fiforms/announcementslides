<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('play_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            // Null follows the entity's Main Show (also the fallback if the
            // chosen show is later deleted).
            $table->foreignId('show_id')->nullable()->constrained()->nullOnDelete();
            // Null plays every language (the same filter as the dashboard).
            $table->foreignId('language_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            // The URL secret itself. Kept readable (not hashed) so a leader
            // can copy the link again later; it's only ever compared via the
            // unique index and never logged.
            $table->string('token', 64)->unique();
            $table->unsignedSmallInteger('delay_seconds');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('play_links');
    }
};
