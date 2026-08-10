<?php

namespace App\Console\Commands;

use App\Models\SlideAnnouncerRelease;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SlideAnnouncerPublishRelease extends Command
{
    protected $signature = 'slide-announcer:publish-release
                            {kind : os|app}
                            {path : Local filesystem path to the release file (.raucb for os, .tar.gz for app)}
                            {version : Version string, e.g. 2026.08.1 or 0.2.0}
                            {channel : stable|testing|developer}
                            {--activate : Activate this release on its channel immediately}';

    protected $description = 'Upload an OS bundle or local-app archive and register it as a slide_announcer_releases row';

    public function handle(): int
    {
        $kind = $this->argument('kind');
        $path = $this->argument('path');
        $version = $this->argument('version');
        $channel = $this->argument('channel');

        if (! in_array($kind, SlideAnnouncerRelease::KINDS, true)) {
            $this->error('Kind must be one of: ' . implode(', ', SlideAnnouncerRelease::KINDS) . '.');
            return self::FAILURE;
        }

        if (! in_array($channel, ['stable', 'testing', 'developer'], true)) {
            $this->error('Channel must be one of: stable, testing, developer.');
            return self::FAILURE;
        }

        if (! is_file($path)) {
            $this->error("No such file: {$path}");
            return self::FAILURE;
        }

        $sha256 = hash_file('sha256', $path);
        // Keep the source file's own extension (.raucb, .tar.gz, ...)
        // rather than assuming one per kind — this command doesn't care
        // what's actually inside the file, only the model/heartbeat
        // contract's kind field does.
        $extension = $this->fullExtension($path);
        $diskPath = "slide-announcer/releases/{$kind}/{$channel}/{$version}{$extension}";

        Storage::disk('public')->put($diskPath, file_get_contents($path));

        $release = SlideAnnouncerRelease::create([
            'kind' => $kind,
            'version' => $version,
            'channel' => $channel,
            'disk_path' => $diskPath,
            'sha256' => $sha256,
        ]);

        if ($this->option('activate')) {
            $release->activate();
            $this->info("Published and activated {$kind} {$version} on {$channel}.");
        } else {
            $this->info("Published {$kind} {$version} on {$channel} (not activated — pass --activate to enable it).");
        }

        return self::SUCCESS;
    }

    /**
     * basename()'s own extension-stripping only handles one dot (e.g.
     * "tar.gz" -> ".gz"), which would leave the wrong extension on the
     * stored path — this keeps everything after the first dot instead.
     */
    private function fullExtension(string $path): string
    {
        $name = basename($path);
        $firstDot = strpos($name, '.');
        return $firstDot === false ? '' : substr($name, $firstDot);
    }
}
