<?php

namespace App\Services\Widgets;

use App\Models\User;
use App\Models\Widget;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * Installs (or upgrades) a widget from a zip archive or a directory.
 *
 * A package is untrusted until it passes every check here: entry names are
 * normalized and anything absolute, containing "..", a backslash or a NUL,
 * or marked as a symlink is rejected; only whitelisted file extensions are
 * accepted; file count and unpacked size are capped (sizes are enforced
 * while reading, not trusted from the zip header). A single wrapping folder
 * (what "compress this folder" produces) is stripped, and OS clutter
 * (__MACOSX/, .DS_Store) is ignored.
 */
class WidgetInstaller
{
    public function installZip(string $zipPath, ?User $by = null): Widget
    {
        if (!is_file($zipPath)) {
            throw new WidgetPackageException(["{$zipPath} is not a file."]);
        }
        if (filesize($zipPath) > config('widgets.max_zip_bytes')) {
            throw new WidgetPackageException(['The archive is too large.']);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new WidgetPackageException(['That file is not a readable zip archive.']);
        }

        try {
            $files = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || str_ends_with($name, '/') || $this->isClutter($name)) {
                    continue;
                }
                $zip->getExternalAttributesIndex($i, $opsys, $attr);
                if ($opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0o170000) === 0o120000) {
                    throw new WidgetPackageException(["\"{$name}\" is a symbolic link, which isn't allowed."]);
                }

                $limit = config('widgets.max_unpacked_bytes') - $total;
                $stream = $zip->getStreamIndex($i);
                if (!$stream) {
                    throw new WidgetPackageException(["Couldn't read \"{$name}\" from the archive."]);
                }
                $bytes = stream_get_contents($stream, $limit + 1);
                fclose($stream);
                if ($bytes === false || strlen($bytes) > $limit) {
                    throw new WidgetPackageException(['The package is too large once unpacked.']);
                }
                $total += strlen($bytes);
                $files[$name] = $bytes;
            }
        } finally {
            $zip->close();
        }

        return $this->install($files, $by);
    }

    public function installDirectory(string $dir, ?User $by = null): Widget
    {
        $dir = rtrim(realpath($dir) ?: $dir, '/');
        if (!is_dir($dir)) {
            throw new WidgetPackageException(["{$dir} is not a directory."]);
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($dir) + 1);
            if ($file->isLink()) {
                throw new WidgetPackageException(["\"{$relative}\" is a symbolic link, which isn't allowed."]);
            }
            if ($file->isFile() && !$this->isClutter($relative)) {
                $files[$relative] = file_get_contents($file->getPathname());
            }
        }

        return $this->install($files, $by);
    }

    /**
     * @param  array<string, string>  $files  raw entry name => contents
     */
    public function install(array $files, ?User $by = null): Widget
    {
        $files = $this->normalizeEntries($files);

        if (!isset($files['manifest.json'])) {
            throw new WidgetPackageException(['The package has no manifest.json at its top level.']);
        }
        $raw = json_decode($files['manifest.json'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new WidgetPackageException(['manifest.json is not valid JSON: ' . json_last_error_msg()]);
        }
        $manifest = WidgetManifest::validate($raw, array_keys($files));

        $slug = $manifest['id'];
        $version = $manifest['version'];
        $existing = Widget::where('slug', $slug)->first();
        $oldDir = $existing?->directory();
        $newDir = Widget::directoryFor($slug, $version);

        $disk = Widget::disk();
        foreach ($files as $path => $bytes) {
            $disk->put($newDir . '/' . $path, $bytes);
        }
        // Reinstalling the same version: drop files the new copy no longer has.
        foreach ($disk->allFiles($newDir) as $stored) {
            if (!isset($files[substr($stored, strlen($newDir) + 1)])) {
                $disk->delete($stored);
            }
        }

        $widget = DB::transaction(fn () => Widget::updateOrCreate(['slug' => $slug], [
            'name'         => $manifest['name'],
            'version'      => $version,
            'description'  => $manifest['description'],
            'manifest'     => $manifest,
            'files'        => array_keys($files),
            'installed_by' => $by?->id,
        ] + ($existing ? [] : ['enabled' => true])));

        if ($oldDir && $oldDir !== $newDir) {
            $disk->deleteDirectory($oldDir);
        }

        return $widget;
    }

    public function uninstall(Widget $widget): void
    {
        Widget::disk()->deleteDirectory("widgets/{$widget->slug}");
        $widget->delete();
    }

    /**
     * @return array<string, string>  clean relative path => contents
     */
    private function normalizeEntries(array $files): array
    {
        if (!$files) {
            throw new WidgetPackageException(['The package is empty.']);
        }
        if (count($files) > config('widgets.max_files')) {
            throw new WidgetPackageException(['The package has too many files.']);
        }

        $errors = [];
        $clean = [];
        $types = config('widgets.file_types');
        foreach ($files as $name => $bytes) {
            $name = (string) $name;
            if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/')
                || preg_match('#(^|/)\.\.?(/|$)#', $name) || preg_match('/^[a-z]:/i', $name)) {
                $errors[] = "\"{$name}\" has an unsafe path.";
                continue;
            }
            if (!preg_match('#^[A-Za-z0-9._\-/@ ]+$#', $name)) {
                $errors[] = "\"{$name}\" has characters that aren't allowed in a file name.";
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!isset($types[$ext])) {
                $errors[] = "\"{$name}\" has a file type that isn't allowed in a widget.";
                continue;
            }
            $clean[$name] = $bytes;
        }
        if ($errors) {
            throw new WidgetPackageException($errors);
        }

        // Strip one wrapping folder when every file lives under it.
        $first = explode('/', array_key_first($clean))[0];
        if (!isset($clean['manifest.json']) && isset($clean["{$first}/manifest.json"])
            && collect(array_keys($clean))->every(fn ($n) => str_starts_with($n, "{$first}/"))) {
            $clean = collect($clean)->mapWithKeys(fn ($b, $n) => [substr($n, strlen($first) + 1) => $b])->all();
        }

        ksort($clean);

        return $clean;
    }

    private function isClutter(string $name): bool
    {
        return str_starts_with($name, '__MACOSX/') || str_contains($name, '/__MACOSX/')
            || basename($name) === '.DS_Store' || basename($name) === 'Thumbs.db';
    }
}
