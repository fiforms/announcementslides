<?php

namespace App\Providers;

use App\Models\Widget;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Services\Backup\DatabaseDumper::class, \App\Services\Backup\MysqlDumper::class);
        $this->app->scoped('widgets.enabled', fn () => Widget::where('enabled', true)->get()->keyBy('slug')->all());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
