<?php

use App\Models\Show;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    // Shows now hold every language (devices and web pages filter instead),
    // so any language previously set on a show is dropped and each
    // auto-filling show is refilled with the slides that language had been
    // excluding.
    public function up(): void
    {
        Show::whereNotNull('language_id')->update(['language_id' => null]);

        Show::where(fn ($q) => $q->where('auto_fill_global', true)->orWhere('auto_fill_nearby', true))
            ->get()
            ->each(fn (Show $show) => $show->syncAutoFillFromCandidates());
    }

    public function down(): void
    {
        // The per-show languages that were cleared aren't recoverable.
    }
};
