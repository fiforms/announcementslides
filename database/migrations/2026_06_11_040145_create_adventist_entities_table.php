<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adventist_entities', function (Blueprint $table) {
            $table->id();
            $table->string('conference_code', 20)->index();
            $table->string('om_entity_id')->nullable()->index();
            $table->string('org_mast_id')->nullable();
            $table->string('entity_type')->nullable();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->string('country')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->string('pastor')->nullable();
            $table->string('language')->nullable();
            $table->string('facebook')->nullable();
            $table->string('instagram')->nullable();
            $table->string('twitter')->nullable();
            $table->string('youtube')->nullable();
            $table->json('extra')->nullable();
            $table->timestamps();

            $table->unique(['conference_code', 'om_entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adventist_entities');
    }
};
