<?php

namespace App\Http\Middleware;

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
            'flash' => [
                'success' => session('success'),
                'error'   => session('error'),
            ],
        ];
    }
}
