<?php

namespace App\Services\Widgets;

use App\Models\ShowOverlay;
use App\Models\SlideMedia;
use App\Models\Widget;

/**
 * Widget placements on a slide overlay. The overlay editor stores widget
 * elements in its embedded source like any other element; on save they're
 * validated here (widget installed, geometry sane, parameters cleaned
 * against the manifest) and copied into slide_media.overlay_settings,
 * which is what the players and the widget-data endpoint read — never the
 * SVG.
 */
class OverlayWidgets
{
    public const MAX_WIDGETS = 20;

    /**
     * Validates the source's widget elements in place (params replaced by
     * their cleaned values) and returns the overlay_settings payload.
     *
     * @param  array  $source  decoded editor source ({v, canvas, elements})
     * @return array{0: array, 1: array{widgets: array}}  [updated source, overlay_settings]
     *
     * @throws WidgetPackageException
     */
    public function fromSource(array $source): array
    {
        $installed = Widget::all()->keyBy('slug');
        $placements = [];
        $errors = [];

        foreach ($source['elements'] as $i => $el) {
            if (!is_array($el) || ($el['type'] ?? null) !== 'widget') {
                continue;
            }
            $widget = $installed[$el['widget'] ?? ''] ?? null;
            if (!$widget) {
                $errors[] = 'The "' . ($el['widget'] ?? '?') . '" widget is no longer installed — remove it from the overlay to save.';
                continue;
            }
            if (!is_string($el['id'] ?? null) || !preg_match('/^as-el-\d{1,6}$/', $el['id'])) {
                $errors[] = 'A widget layer has an invalid id.';
                continue;
            }
            $box = [];
            foreach (['x', 'y', 'w', 'h'] as $f) {
                if (!is_int($el[$f] ?? null) && !is_float($el[$f] ?? null)) {
                    $errors[] = "{$widget->name}: position and size must be numbers.";
                    continue 2;
                }
                $box[$f] = round($el[$f], 2);
            }
            if ($box['w'] < 1 || $box['h'] < 1 || $box['w'] > 10000 || $box['h'] > 10000) {
                $errors[] = "{$widget->name}: size is out of range.";
                continue;
            }

            try {
                $params = WidgetParams::clean($widget, $el['params'] ?? []);
            } catch (WidgetPackageException $e) {
                $errors = [...$errors, ...array_map(fn ($m) => "{$widget->name}: {$m}", $e->errors)];
                continue;
            }
            $source['elements'][$i]['params'] = $params;

            if (!empty($el['hidden'])) {
                continue;
            }
            $placements[] = [
                'id'      => $el['id'],
                'widget'  => $widget->slug,
                ...$box,
                'opacity' => max(0, min(1, (float) ($el['opacity'] ?? 1))),
                'params'  => $params,
            ];
        }

        if (count($placements) > self::MAX_WIDGETS) {
            $errors[] = 'An overlay can hold at most ' . self::MAX_WIDGETS . ' widgets.';
        }
        if ($errors) {
            throw new WidgetPackageException($errors);
        }

        return [$source, ['widgets' => $placements]];
    }

    /**
     * Placements ready for a player: enabled widgets only, each with its
     * module and data URLs.
     */
    public function forPlayer(?SlideMedia $overlay): array
    {
        return $this->playerPlacements(
            $overlay?->overlay_settings['widgets'] ?? [],
            fn (array $p) => route('widget-data.show', ['slideMedia' => $overlay->id, 'element' => $p['id'], 'endpoint' => '__endpoint__'])
        );
    }

    /**
     * forPlayer() for a show's pinned widget layer (widget-data.show-overlay).
     * $playLinkToken swaps in the no-login shared-link endpoint.
     */
    public function forShowPlayer(?ShowOverlay $overlay, ?string $playLinkToken = null): array
    {
        return $this->playerPlacements(
            $overlay?->overlay_settings['widgets'] ?? [],
            fn (array $p) => $playLinkToken
                ? route('play.widget-data-show', ['token' => $playLinkToken, 'element' => $p['id'], 'endpoint' => '__endpoint__'])
                : route('widget-data.show-overlay', ['show' => $overlay->show_id, 'element' => $p['id'], 'endpoint' => '__endpoint__'])
        );
    }

    private function playerPlacements(array $placements, callable $dataUrl): array
    {
        if (!$placements) {
            return [];
        }
        $enabled = Widget::enabledBySlug();

        return collect($placements)
            ->filter(fn ($p) => isset($enabled[$p['widget'] ?? '']))
            ->map(fn ($p) => [
                ...$p,
                'version'   => $enabled[$p['widget']]->version,
                'entry_url' => $enabled[$p['widget']]->entryUrl(),
                'data_url'  => $dataUrl($p),
            ])
            ->values()
            ->all();
    }

    /**
     * Placements for a Slide Announcer device: like forPlayer() but with
     * no URLs — the device mirrors bundles locally and serves them (and
     * proxies data) from its own loopback nginx, so it adds its own. The
     * overlay id rides along for the device's data requests. Also used for a
     * show's pinned layer (pass its overlay_settings widgets).
     */
    public function forDevice(?SlideMedia $overlay): array
    {
        return $this->devicePlacements($overlay?->overlay_settings['widgets'] ?? []);
    }

    public function forShowDevice(?ShowOverlay $overlay): array
    {
        return $this->devicePlacements($overlay?->overlay_settings['widgets'] ?? []);
    }

    private function devicePlacements(array $placements): array
    {
        $enabled = Widget::enabledBySlug();

        return collect($placements)
            ->filter(fn ($p) => isset($enabled[$p['widget'] ?? '']))
            ->map(fn ($p) => [...$p, 'version' => $enabled[$p['widget']]->version])
            ->values()
            ->all();
    }

    /**
     * The bundles a device must mirror for these placements: each enabled
     * widget's current version and every file in it, with download URLs.
     */
    public function bundlesFor(array $placements): array
    {
        $enabled = Widget::enabledBySlug();

        return collect($placements)->pluck('widget')->unique()
            ->filter(fn ($slug) => isset($enabled[$slug]))
            ->map(fn ($slug) => [
                'slug'    => $slug,
                'version' => $enabled[$slug]->version,
                'entry'   => $enabled[$slug]->manifest['entry'],
                'files'   => collect($enabled[$slug]->files)->map(fn ($path) => [
                    'path' => $path,
                    'url'  => $enabled[$slug]->assetUrl($path),
                ])->all(),
            ])
            ->values()
            ->all();
    }

    public static function placement(SlideMedia|ShowOverlay $overlay, string $elementId): ?array
    {
        return collect($overlay->overlay_settings['widgets'] ?? [])->firstWhere('id', $elementId);
    }
}
