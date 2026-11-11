<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // viewer = follower; editor = manages the entity's slides/shows/links;
        // admin = entity leader (editor powers, plus managing editors).
        Schema::table('user_entities', function (Blueprint $table) {
            $table->enum('role', ['admin', 'editor', 'viewer'])->default('viewer')->change();
        });

        // A leader's invitation for someone with no account yet. Applied (and
        // deleted) when that email first signs in. Separate from
        // user_invitations, which carries a *global* role and is unique per email.
        Schema::create('entity_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['editor'])->default('editor');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['email', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_invitations');

        // Editors fall back to followers before the enum loses the value.
        DB::table('user_entities')->where('role', 'editor')->update(['role' => 'viewer']);

        Schema::table('user_entities', function (Blueprint $table) {
            $table->enum('role', ['admin', 'viewer'])->default('viewer')->change();
        });
    }
};
