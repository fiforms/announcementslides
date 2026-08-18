<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_show_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('shows', function (Blueprint $table) {
            $table->foreignId('global_template_id')->nullable()->after('entity_id')
                ->constrained('global_show_templates')->cascadeOnDelete();
            $table->boolean('auto_delete_when_empty')->default(false)->after('is_main');
        });
    }

    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('global_template_id');
            $table->dropColumn('auto_delete_when_empty');
        });

        Schema::dropIfExists('global_show_templates');
    }
};
