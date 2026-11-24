<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's database-notifications table plus the columns the inbox needs:
 * `category` (preferences/filtering), `archived_at` ("cleared", separate from
 * read), `email_mode` (the recipient's email choice when it was created) and
 * `emailed_at` (the digest's dedupe marker). See app/Services/Notifier.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->string('category', 40);
            $table->string('email_mode', 10)->default('none');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();

            // Bell count + inbox/archive listing for one user.
            $table->index(['notifiable_id', 'archived_at', 'read_at'], 'notifications_inbox_index');
            $table->index(['notifiable_id', 'created_at'], 'notifications_recent_index');
            // Digest sweep and retention prune.
            $table->index(['email_mode', 'emailed_at'], 'notifications_digest_index');
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
