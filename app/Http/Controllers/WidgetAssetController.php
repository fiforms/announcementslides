<?php

namespace App\Http\Controllers;

use App\Models\Widget;

/**
 * Serves installed widget bundle files. Only paths in the installed
 * manifest's file list are reachable (never an arbitrary disk path), only
 * for enabled widgets at their current version — and since the version is
 * in the URL, responses are cached as immutable.
 */
class WidgetAssetController extends Controller
{
    public function show(string $slug, string $version, string $path)
    {
        $widget = Widget::where('slug', $slug)->where('version', $version)->where('enabled', true)->first();
        abort_unless($widget && $widget->hasFile($path), 404);

        $bytes = $widget->readFile($path);
        abort_if($bytes === null, 404);

        $type = config('widgets.file_types.' . strtolower(pathinfo($path, PATHINFO_EXTENSION)), 'application/octet-stream');

        return response($bytes, 200, [
            'Content-Type'           => $type,
            'Cache-Control'          => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
