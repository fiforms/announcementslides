<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->foreignId('language_id')->nullable()->after('name')
                ->constrained('languages')->nullOnDelete();
            $table->boolean('auto_fill')->default(false)->after('is_main');
        });

        // Main shows keep receiving global/nearby slides automatically, same
        // as before this feature existed; language_id stays null (accepts
        // every language) until a leader narrows it down.
        DB::table('shows')->where('is_main', true)->whereNotNull('entity_id')
            ->update(['auto_fill' => true]);
    }

    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('language_id');
            $table->dropColumn('auto_fill');
        });
    }
};
