<?php

use App\Support\SlideToken;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slides', function (Blueprint $table) {
            $table->string('token', 32)->nullable()->unique()->after('id');
        });

        // Existing slides (soft-deleted included) get their public token now;
        // new ones get theirs from Slide's creating hook.
        DB::table('slides')->whereNull('token')->orderBy('id')->pluck('id')->each(
            fn ($id) => DB::table('slides')->where('id', $id)->update(['token' => SlideToken::generate()])
        );
    }

    public function down(): void
    {
        Schema::table('slides', function (Blueprint $table) {
            $table->dropUnique(['token']);
            $table->dropColumn('token');
        });
    }
};
