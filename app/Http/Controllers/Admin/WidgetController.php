<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SlideMedia;
use App\Models\Widget;
use App\Services\Widgets\UrlPolicy;
use App\Services\Widgets\WidgetDataService;
use App\Services\Widgets\WidgetInstaller;
use App\Services\Widgets\WidgetPackageException;
use App\Support\WidgetLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Site-admin management of overlay widgets: install/upgrade from a zip,
 * enable/disable, per-widget settings (API keys) and extra URL allowlist
 * prefixes, uninstall. Installing a widget lets its code run for every
 * viewer of a slide that uses it, so this stays behind EnsureAdmin.
 */
class WidgetController extends Controller
{
    public function index(): Response
    {
        $usage = $this->usageCounts();

        return Inertia::render('Admin/Widgets', [
            'defaultLocation' => WidgetLocation::default(),
            'widgets' => Widget::with('installer')->orderBy('name')->get()->map(fn (Widget $w) => [
                'id'          => $w->id,
                'slug'        => $w->slug,
                'name'        => $w->name,
                'version'     => $w->version,
                'description' => $w->description,
                'enabled'     => $w->enabled,
                'icon_url'    => $w->iconUrl(),
                'installed_by' => $w->installer?->name,
                'updated_at'  => $w->updated_at?->toIso8601String(),
                'used_by'     => $usage[$w->slug] ?? 0,
                'hosts'       => $this->endpointHosts($w),
                'url_params'  => collect($w->manifest['parameters'] ?? [])
                    ->filter(fn ($p) => $p['type'] === 'url' && isset($p['allow']))
                    ->map(fn ($p, $key) => [
                        'key'      => $key,
                        'label'    => $p['label'] ?? $key,
                        'declared' => $p['allow'],
                        'extra'    => $w->extra_allow[$key] ?? [],
                    ])->values(),
                'settings'    => collect($w->manifest['settings'] ?? [])->map(fn ($s, $key) => [
                    'key'    => $key,
                    'label'  => $s['label'] ?? $key,
                    'help'   => $s['help'] ?? null,
                    'secret' => (bool) ($s['secret'] ?? true),
                    'is_set' => $w->setting($key) !== null,
                ])->values(),
                'errors'      => Cache::get(WidgetDataService::errorsKey($w->slug), []),
            ]),
        ]);
    }

    public function store(Request $request, WidgetInstaller $installer): RedirectResponse
    {
        $request->validate([
            'package' => ['required', 'file', 'max:' . intdiv(config('widgets.max_zip_bytes'), 1024)],
        ]);
        $file = $request->file('package');
        if (strtolower($file->getClientOriginalExtension()) !== 'zip') {
            throw ValidationException::withMessages(['package' => 'Upload the widget as a .zip file.']);
        }

        try {
            $widget = $installer->installZip($file->getRealPath(), $request->user());
        } catch (WidgetPackageException $e) {
            throw ValidationException::withMessages(['package' => $e->errors]);
        }

        return back()->with('success', "Installed {$widget->name} {$widget->version}.");
    }

    public function update(Request $request, Widget $widget): RedirectResponse
    {
        $data = $request->validate([
            'enabled'       => 'sometimes|boolean',
            'settings'      => 'sometimes|array',
            'settings.*'    => 'nullable|string|max:2000',
            'clear_settings'   => 'sometimes|array',
            'clear_settings.*' => 'string',
            'extra_allow'   => 'sometimes|array',
            'extra_allow.*' => 'array|max:50',
            'extra_allow.*.*' => 'string|max:500',
        ]);

        if (array_key_exists('enabled', $data)) {
            $widget->enabled = $data['enabled'];
        }

        if (isset($data['settings']) || isset($data['clear_settings'])) {
            $declared = $widget->manifest['settings'] ?? [];
            $settings = $widget->settings ?? [];
            // A blank field keeps the current value (secrets are never sent
            // back to the page); clearing is explicit.
            foreach ($data['settings'] ?? [] as $key => $value) {
                if (isset($declared[$key]) && $value !== null) {
                    $settings[$key] = $value;
                }
            }
            foreach ($data['clear_settings'] ?? [] as $key) {
                unset($settings[$key]);
            }
            $widget->settings = $settings ?: null;
        }

        if (isset($data['extra_allow'])) {
            $extra = [];
            $errors = [];
            foreach ($data['extra_allow'] as $param => $prefixes) {
                if (($widget->manifest['parameters'][$param]['type'] ?? null) !== 'url'
                    || !isset($widget->manifest['parameters'][$param]['allow'])) {
                    continue;
                }
                foreach (array_filter(array_map('trim', $prefixes)) as $prefix) {
                    $normalized = UrlPolicy::normalize($prefix);
                    if ($normalized === null || str_contains($prefix, '?') || UrlPolicy::host($normalized) === '') {
                        $errors[] = "\"{$prefix}\" isn't an https:// URL prefix.";
                        continue;
                    }
                    $extra[$param][] = $normalized;
                }
            }
            if ($errors) {
                throw ValidationException::withMessages(['extra_allow' => $errors]);
            }
            $widget->extra_allow = $extra ?: null;
        }

        $widget->save();

        return back()->with('success', "Saved {$widget->name}.");
    }

    /**
     * The site-wide fallback for widgets' api.location, used by screens
     * whose church has no coordinates (and by pages with no church).
     */
    public function updateLocation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'      => 'nullable|required_with:latitude,longitude|string|max:80',
            'latitude'  => 'nullable|required_with:name|numeric|between:-90,90',
            'longitude' => 'nullable|required_with:name|numeric|between:-180,180',
        ]);

        WidgetLocation::saveDefault(empty($data['name']) ? null : [
            'name'      => trim($data['name']),
            'latitude'  => round((float) $data['latitude'], 4),
            'longitude' => round((float) $data['longitude'], 4),
        ]);

        return back()->with('success', empty($data['name']) ? 'Default location cleared.' : 'Default location saved.');
    }

    public function destroy(Widget $widget, WidgetInstaller $installer): RedirectResponse
    {
        $installer->uninstall($widget);

        return back()->with('success', "Removed {$widget->name}. Slides that used it keep their placement but show nothing there.");
    }

    /** @return array<string, int> widget slug => number of overlays placing it */
    private function usageCounts(): array
    {
        $counts = [];
        SlideMedia::where('media_type', 'slide-overlay')->whereNotNull('overlay_settings')
            ->whereHas('slide')
            ->select('id', 'overlay_settings')
            ->each(function (SlideMedia $m) use (&$counts) {
                foreach (collect($m->overlay_settings['widgets'] ?? [])->pluck('widget')->unique() as $slug) {
                    $counts[$slug] = ($counts[$slug] ?? 0) + 1;
                }
            });

        return $counts;
    }

    /**
     * The upstream hosts this widget can make the server contact — its
     * network permission, shown at a glance on the admin page.
     */
    private function endpointHosts(Widget $widget): array
    {
        return collect($widget->manifest['endpoints'] ?? [])->map(function ($e) use ($widget) {
            if (preg_match('/^\{([a-z][a-z0-9_]*)\}$/', $e['url'], $m)) {
                $allow = $widget->allowFor($m[1]);
                return $allow === null
                    ? 'any public https:// address (' . $m[1] . ')'
                    : collect($allow)->map(fn ($p) => UrlPolicy::host(UrlPolicy::normalize($p) ?? ''))->unique()->implode(', ');
            }
            return UrlPolicy::host(UrlPolicy::normalize(preg_replace('/\{[^}]*\}/', 'x', $e['url'])) ?? '');
        })->filter()->unique()->values()->all();
    }
}
