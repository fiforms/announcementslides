<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Site-wide key/value settings (see the app_settings migration). Values are
 * JSON, read through a shared cache that put() invalidates.
 */
class AppSetting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    private const CACHE_KEY = 'app_settings';

    /** @return array<string, mixed> every stored setting */
    public static function stored(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()
            ->get()
            ->mapWithKeys(fn (self $s) => [$s->key => $s->value['v'] ?? null])
            ->all());
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::stored()[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
        Cache::forget(self::CACHE_KEY);
    }
}
