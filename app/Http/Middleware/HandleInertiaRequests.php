<?php

namespace App\Http\Middleware;

use App\Models\Widget;
use App\Support\UpscalerSettings;
use App\Support\WidgetLocation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'isDev'   => app()->environment('local'),
            'auth' => [
                'user'             => $request->user()?->only('id', 'name', 'email', 'role', 'avatar_url'),
                'admin_entities'   => $request->user()?->adminEntities()->get(['entities.id', 'entities.name']) ?? [],
                'user_entities'    => $request->user()?->entities()->orderBy('name')->get(['entities.id', 'entities.name']) ?? [],
            ],
            // Options for the in-browser upscaler (uploads and the media manager).
            'upscaler' => fn () => $request->user() ? UpscalerSettings::forClient() : null,
            // Overlay widgets' api.location (see WidgetLocation) — only
            // looked up when any widget is installed.
            'widgetLocation' => fn () => Widget::enabledBySlug() ? WidgetLocation::forRequest($request) : null,
            'flash' => [
                'success' => session('success'),
                'error'   => session('error'),
            ],
        ];
    }
}
