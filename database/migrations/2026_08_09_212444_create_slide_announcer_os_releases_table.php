<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "is_active per channel" is enforced in the model/command (activating a
     * release deactivates any other active release on the same channel),
     * not a DB partial-unique-index — keeps this portable between SQLite
     * (dev) and MySQL (prod), matching this app's existing DB-portability
     * calls (see NearbyEntities::within()).
     */
    public function up(): void
    {
        Schema::create('slide_announcer_os_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version');
            $table->enum('channel', ['stable', 'testing', 'developer']);
            $table->string('bundle_disk_path');
            $table->string('sha256');
            $table->boolean('is_active')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['channel', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slide_announcer_os_releases');
    }
};
