<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * An admin-installed overlay widget: a manifest plus a bundle of files
 * (ES module entry, icon, assets) stored under widgets/{slug}/{version}/ on
 * config('widgets.disk'). See App\Services\Widgets\WidgetInstaller.
 */
class Widget extends Model
{
    protected $fillable = [
        'slug', 'name', 'version', 'description', 'manifest', 'files',
        'enabled', 'settings', 'extra_allow', 'installed_by',
    ];

    protected function casts(): array
    {
        return [
            'manifest'    => 'array',
            'files'       => 'array',
            'enabled'     => 'boolean',
            'settings'    => 'encrypted:array',
            'extra_allow' => 'array',
        ];
    }

    public function installer()
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    /**
     * Enabled widgets keyed by slug, loaded once per request or queue job —
     * slide listings and thumbnail jobs resolve every overlay's widgets
     * against this. A scoped binding (see AppServiceProvider), not once():
     * the queue worker resets scoped instances between jobs, so a
     * long-running worker sees widgets installed or toggled after it started.
     *
     * @return array<string, Widget>
     */
    public static function enabledBySlug(): array
    {
        return app('widgets.enabled');
    }

    public static function directoryFor(string $slug, string $version): string
    {
        return "widgets/{$slug}/{$version}";
    }

    public function directory(): string
    {
        return static::directoryFor($this->slug, $this->version);
    }

    public static function disk()
    {
        return Storage::disk(config('widgets.disk'));
    }

    public function hasFile(string $path): bool
    {
        return in_array($path, $this->files ?? [], true);
    }

    public function readFile(string $path): ?string
    {
        if (!$this->hasFile($path)) {
            return null;
        }

        return static::disk()->get($this->directory() . '/' . $path);
    }

    public function assetUrl(string $path): string
    {
        return route('widgets.asset', ['slug' => $this->slug, 'version' => $this->version, 'path' => $path]);
    }

    public function entryUrl(): string
    {
        return $this->assetUrl($this->manifest['entry']);
    }

    public function iconUrl(): string
    {
        return $this->assetUrl($this->manifest['icon']);
    }

    /**
     * URL-prefix allowlist for one url parameter: the manifest's own
     * entries plus any the admin added. Null means "any public https URL".
     *
     * @return string[]|null
     */
    public function allowFor(string $param): ?array
    {
        $declared = $this->manifest['parameters'][$param]['allow'] ?? null;
        $extra = $this->extra_allow[$param] ?? [];
        if ($declared === null) {
            return null;
        }

        return array_values(array_unique([...$declared, ...$extra]));
    }

    public function setting(string $key): ?string
    {
        $value = $this->settings[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The manifest subset the overlay editor needs (no secrets, no file list).
     */
    /**
     * The widgets the overlay editor can place: every enabled one, plus any
     * already placed (by slug) so a disabled one still shows its placeholder.
     */
    public static function editorCatalog(array $placements = []): \Illuminate\Support\Collection
    {
        return static::orderBy('name')->get()
            ->filter(fn (self $w) => $w->enabled || collect($placements)->contains('widget', $w->slug))
            ->map(fn (self $w) => $w->editorResource() + ['enabled' => $w->enabled])
            ->values();
    }

    public function editorResource(): array
    {
        $m = $this->manifest;

        return [
            'slug'         => $this->slug,
            'name'         => $this->name,
            'version'      => $this->version,
            'description'  => $this->description,
            'icon_url'     => $this->iconUrl(),
            'entry_url'    => $this->entryUrl(),
            'default_size' => $m['defaultSize'] ?? ['w' => 400, 'h' => 300],
            'aspect_locked' => (bool) ($m['aspectLocked'] ?? false),
            'parameters'   => collect($m['parameters'] ?? [])->map(function ($p, $key) {
                return array_filter([
                    'allow'     => $p['type'] === 'url' ? $this->allowFor($key) : null,
                    'type'      => $p['type'],
                    'label'     => $p['label'] ?? null,
                    'help'      => $p['help'] ?? null,
                    'default'   => $p['default'] ?? null,
                    'options'   => $p['options'] ?? null,
                    'min'       => $p['min'] ?? null,
                    'max'       => $p['max'] ?? null,
                    'step'      => $p['step'] ?? null,
                    'maxLength' => $p['maxLength'] ?? null,
                ], fn ($v) => $v !== null);
            })->all(),
        ];
    }
}
