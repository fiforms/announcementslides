<?php

namespace App\Console\Commands;

use App\Models\SlideAnnouncerOsRelease;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SlideAnnouncerPublishOsRelease extends Command
{
    protected $signature = 'slide-announcer:publish-os-release
                            {path : Local filesystem path to the .raucb bundle}
                            {version : Version string, e.g. 2026.08.1}
                            {channel : stable|testing|developer}
                            {--activate : Activate this release on its channel immediately}';

    protected $description = 'Upload a RAUC OS bundle and register it as a slide_announcer_os_releases row';

    public function handle(): int
    {
        $path = $this->argument('path');
        $version = $this->argument('version');
        $channel = $this->argument('channel');

        if (! in_array($channel, ['stable', 'testing', 'developer'], true)) {
            $this->error('Channel must be one of: stable, testing, developer.');
            return self::FAILURE;
        }

        if (! is_file($path)) {
            $this->error("No such file: {$path}");
            return self::FAILURE;
        }

        $sha256 = hash_file('sha256', $path);
        $diskPath = "slide-announcer/os-releases/{$channel}/{$version}.raucb";

        Storage::disk('public')->put($diskPath, file_get_contents($path));

        $release = SlideAnnouncerOsRelease::create([
            'version' => $version,
            'channel' => $channel,
            'bundle_disk_path' => $diskPath,
            'sha256' => $sha256,
        ]);

        if ($this->option('activate')) {
            $release->activate();
            $this->info("Published and activated {$version} on {$channel}.");
        } else {
            $this->info("Published {$version} on {$channel} (not activated — pass --activate to enable it).");
        }

        return self::SUCCESS;
    }
}
