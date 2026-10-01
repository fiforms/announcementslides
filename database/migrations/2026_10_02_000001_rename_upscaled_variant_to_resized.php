<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Downscaling oversized images reuses the original/upscaled mechanism, so
     * the second version is now called 'resized' and records its `kind`
     * ('upscale' | 'downscale') and, for upscales, the `model`.
     */
    public function up(): void
    {
        $this->convert(fn (array $v) => $this->rename($v, 'upscaled', 'resized', fn ($snap) => [
            'kind' => 'upscale', 'model' => $snap['upscale_model'] ?? null,
        ] + array_diff_key($snap, ['upscale_model' => 1])), 'upscaled', 'resized');
    }

    public function down(): void
    {
        $this->convert(fn (array $v) => $this->rename($v, 'resized', 'upscaled', fn ($snap) => [
            'upscale_model' => $snap['model'] ?? null,
        ] + array_diff_key($snap, ['kind' => 1, 'model' => 1])), 'resized', 'upscaled');
    }

    private function rename(array $variants, string $from, string $to, callable $fix): array
    {
        if (isset($variants[$from])) {
            $variants[$to] = $fix($variants[$from]);
            unset($variants[$from]);
        }

        return $variants;
    }

    private function convert(callable $fn, string $oldActive, string $newActive): void
    {
        DB::table('slide_media')->whereNotNull('variants')->orderBy('id')->each(function ($row) use ($fn, $oldActive, $newActive) {
            DB::table('slide_media')->where('id', $row->id)->update([
                'variants'       => json_encode($fn(json_decode($row->variants, true) ?? [])),
                'active_variant' => $row->active_variant === $oldActive ? $newActive : $row->active_variant,
            ]);
        });
    }
};
