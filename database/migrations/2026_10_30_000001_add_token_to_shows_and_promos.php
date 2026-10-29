<?php

use App\Models\GlobalShowTemplate;
use App\Models\Show;
use App\Support\PublicToken;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['shows' => Show::class, 'global_show_templates' => GlobalShowTemplate::class];

    public function up(): void
    {
        foreach (self::TABLES as $table => $model) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('token', 32)->nullable()->unique()->after('id');
            });

            // Existing rows (soft-deleted shows included) get their public token
            // now; new ones get theirs from HasPublicToken.
            DB::table($table)->whereNull('token')->orderBy('id')->pluck('id')->each(
                fn ($id) => DB::table($table)->where('id', $id)->update(['token' => PublicToken::generate($model)])
            );
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropUnique(['token']);
                $t->dropColumn('token');
            });
        }
    }
};
