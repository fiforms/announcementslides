<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Widget;
use App\Services\OverlaySource;
use App\Services\SvgSanitizer;
use App\Services\Widgets\OverlayWidgets;
use App\Services\Widgets\WidgetPackageException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The two halves of talking to the overlay editor, shared by everything that
 * stores an editor-made overlay: a slide's or show's (ManagesSlideMedia) and a
 * library template's.
 */
trait BuildsOverlayFiles
{
    /**
     * The editor's load response. `source` is the embedded editor model,
     * present only when the file was made by the editor and hasn't been
     * modified since (see OverlaySource); otherwise the overlay content is
     * returned inline (sanitized SVG markup or a raster data URI) so the
     * editor can keep it as a fixed base layer. `widgets` is the catalog of
     * installed widgets the editor can place.
     *
     * @param  array  $savedWidgets  widget placements already saved with the overlay
     */
    protected function overlayEditorResponse(?string $path, ?string $mime, array $savedWidgets): JsonResponse
    {
        $widgets = Widget::editorCatalog($savedWidgets);

        if (!$path) {
            return response()->json(['source' => null, 'overlay' => null, 'widgets' => $widgets]);
        }

        $bytes = file_get_contents($path);

        if ($mime !== 'image/svg+xml') {
            return response()->json(['source' => null, 'widgets' => $widgets, 'overlay' => [
                'mime_type' => $mime,
                'data_uri'  => 'data:' . $mime . ';base64,' . base64_encode($bytes),
            ]]);
        }

        $extracted = app(OverlaySource::class)->extract($bytes);
        $svg = app(SvgSanitizer::class)->sanitize($extracted['svg']);

        return response()->json([
            'source'  => $svg === null ? null : $extracted['source'],
            'overlay' => $svg === null ? null : ['mime_type' => 'image/svg+xml', 'svg' => $svg],
            'widgets' => $widgets,
        ]);
    }

    /**
     * Validates the editor's save request: the compiled SVG is sanitized and
     * the editor source embedded into it; widget layers (not in the SVG at
     * all) are validated into overlay_settings (see OverlayWidgets).
     *
     * @return array{0: string, 1: array}  [svg to store, overlay_settings]
     */
    protected function prepareOverlaySave(Request $request): array
    {
        $request->validate([
            'svg'    => 'required|string|max:8388608',
            'source' => 'required|string|max:1048576',
        ]);

        $source = json_decode($request->input('source'), true);
        if (!is_array($source) || !is_int($source['v'] ?? null) || !is_array($source['elements'] ?? null)) {
            throw ValidationException::withMessages(['source' => 'The overlay source is malformed.']);
        }

        try {
            [$source, $overlaySettings] = app(OverlayWidgets::class)->fromSource($source);
        } catch (WidgetPackageException $e) {
            throw ValidationException::withMessages(['source' => $e->errors]);
        }

        $clean = app(SvgSanitizer::class)->sanitize($request->input('svg'));
        if ($clean === null) {
            throw ValidationException::withMessages(['svg' => 'The overlay is not a valid SVG image.']);
        }

        return [app(OverlaySource::class)->embed($clean, $source), $overlaySettings];
    }
}
