<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per backup archive (see App\Services\Backup\BackupService).
     * The archive itself lives on config('backup.disk'); only metadata is
     * stored here. Rows can be rebuilt from the archives' manifest.json
     * (BackupService::syncFromDisk), which matters after a restore replaces
     * this table with an older copy.
     */
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('filename')->nullable()->unique();
            $table->string('mode', 16);                       // database | full
            $table->string('trigger', 16);                    // manual | scheduled | cli | pre-migrate | pre-restore
            $table->string('status', 16)->default('queued');  // queued | running | completed | failed
            $table->unsignedBigInteger('size')->nullable();
            $table->text('error')->nullable();
            $table->json('manifest')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
