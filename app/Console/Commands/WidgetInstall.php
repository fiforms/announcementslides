<?php

namespace App\Console\Commands;

use App\Services\Widgets\WidgetInstaller;
use App\Services\Widgets\WidgetPackageException;
use Illuminate\Console\Command;

class WidgetInstall extends Command
{
    protected $signature = 'widget:install
                            {path* : Widget folder(s) or .zip package(s), e.g. resources/widgets/clock}';

    protected $description = 'Install or upgrade overlay widgets (same checks as the admin upload)';

    public function handle(WidgetInstaller $installer): int
    {
        $status = self::SUCCESS;

        foreach ($this->argument('path') as $path) {
            try {
                $widget = is_dir($path) ? $installer->installDirectory($path) : $installer->installZip($path);
                $this->info("Installed {$widget->name} ({$widget->slug}) {$widget->version}");
            } catch (WidgetPackageException $e) {
                $this->error("{$path}:");
                foreach ($e->errors as $error) {
                    $this->line("  - {$error}");
                }
                $status = self::FAILURE;
            }
        }

        return $status;
    }
}
