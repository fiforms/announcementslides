<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\UpscalerSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin options for the in-browser AI upscaler (see UpscalerSettings and
 * resources/js/Composables/useUpscaler.js). Nothing here runs server-side;
 * the settings are handed to every browser as a shared Inertia prop.
 */
class UpscalerSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/UpscalerSettings', [
            'settings' => UpscalerSettings::all(),
            'models'   => collect(config('slides.upscale.models'))
                ->map(fn ($m, $key) => ['value' => $key] + $m)
                ->values(),
            'patchSizes' => UpscalerSettings::PATCH_SIZES,
            'limits'   => UpscalerSettings::forClient(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled'        => 'required|boolean',
            'auto_on_upload' => 'required|boolean',
            'model'          => ['required', Rule::in(array_keys(config('slides.upscale.models')))],
            'jpeg_quality'   => 'required|integer|min:60|max:100',
            'patch_size'     => ['required', 'integer', Rule::in(UpscalerSettings::PATCH_SIZES)],
            'downscale_oversized' => 'required|boolean',
        ]);

        UpscalerSettings::save($data);

        return back()->with('success', 'Upscaler settings saved.');
    }
}
