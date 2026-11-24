<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Markers for notifications:slide-schedule ("started showing" / "expired"
 * notices). Slides whose start or expiry is already past count as notified,
 * so the first run after this ships doesn't announce history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slides', function (Blueprint $table) {
            $table->timestamp('start_notified_at')->nullable();
            $table->timestamp('expiry_notified_at')->nullable();
        });

        $now = now();
        DB::table('slides')->whereNotNull('publish_at')->where('publish_at', '<=', $now)->update(['start_notified_at' => $now]);
        DB::table('slides')->whereNotNull('expires_at')->where('expires_at', '<=', $now)->update(['expiry_notified_at' => $now]);
    }

    public function down(): void
    {
        Schema::table('slides', fn (Blueprint $table) => $table->dropColumn(['start_notified_at', 'expiry_notified_at']));
    }
};
