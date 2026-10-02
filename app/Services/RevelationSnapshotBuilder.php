<?php

namespace App\Services;

use App\Models\Slide;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Builds a "REVELation Snapshot Presenter" package: a zip holding each
 * slide's base image/video and (separately) its overlay, keyed by slide
 * number, plus a presentation.md that stacks them (the base marked with the
 * special "background" alt text, the overlay as "fill"), a stub stylesheet,
 * a thumbnail of the first slide and a manifest.json listing every file
 * with its size, mtime and SHA-1.
 *
 * Widgets are not exported yet.
 */
class RevelationSnapshotBuilder
{
    private const APP_VERSION = '1.0.13';

    /**
     * Zero-padded slide number (wide enough for $total, minimum 3) plus the
     * filename with anything outside letters, digits, spaces and . _ - ( )
     * replaced by underscores. Unicode letters stay, so accented names
     * survive. The number keeps names unique and sorts the deck on extract.
     */
    public static function entryName(int $position, int $total, string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext  = pathinfo($name, PATHINFO_EXTENSION);

        $clean = fn (string $part) => trim(preg_replace('/[^\p{L}\p{N} ._()-]+/u', '_', $part), ' ._');
        $base  = $clean($base) ?: 'slide';
        $ext   = $clean($ext);

        $width = max(3, strlen((string) $total));

        return sprintf('%0' . $width . 'd_%s%s', $position, $base, $ext === '' ? '' : '.' . $ext);
    }

    /**
     * Writes the package for $slides (already in show order, primaryMedia
     * and overlayMedia loaded) to a temp zip and returns its path; the
     * caller deletes it. Slides without media are skipped.
     */
    public function build(Collection $slides, string $title): string
    {
        $disk  = Storage::disk('public');
        $total = $slides->count();

        /** @var array<string, string> entry name => absolute source path */
        $files    = [];
        $sections = [];
        $position = 0;

        foreach ($slides as $slide) {
            $media = $slide->primaryMedia;
            if (! $media || ! file_exists($disk->path($media->disk_path))) {
                continue;
            }
            $position++;

            $baseName = self::entryName($position, $total, $media->downloadName());
            $files[$baseName] = $disk->path($media->disk_path);

            $overlay = $slide->overlayMedia;
            if (! $overlay || ! file_exists($disk->path($overlay->disk_path))) {
                $sections[] = $this->image('fill', $baseName);
                continue;
            }

            $ext         = pathinfo($overlay->disk_path, PATHINFO_EXTENSION) ?: 'svg';
            $overlayName = self::entryName($position, $total, "overlay.{$ext}");
            $files[$overlayName] = $disk->path($overlay->disk_path);

            $sections[] = $this->image('background', $baseName) . "\n\n" . $this->image('fill', $overlayName);
        }

        if (! $files) {
            throw new RuntimeException('No slides to export.');
        }

        $generated = [
            'presentation.md' => $this->frontMatter($title) . implode("\n\n---\n\n", $sections),
            'style.css'       => "/* Add custom styles here */\n",
        ];

        $thumb = $this->thumbnailPath($slides);
        if ($thumb) {
            $files['presentation.thumb.jpg'] = $thumb;
        }

        $manifest = $this->manifest($files, $generated);

        $tmpFile = tempnam(sys_get_temp_dir(), 'revelation_');
        unlink($tmpFile);

        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Could not create zip archive.');
        }
        foreach ($files as $name => $path) {
            $zip->addFile($path, $name);
        }
        foreach ($generated + ['manifest.json' => $manifest] as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $tmpFile;
    }

    private function image(string $alt, string $filename): string
    {
        // Names with spaces or parentheses need the angle-bracket form.
        $target = preg_match('/[ ()]/', $filename) ? "<{$filename}>" : $filename;

        return "![{$alt}]({$target})";
    }

    private function frontMatter(string $title): string
    {
        $date = now()->toDateString();

        return <<<YAML
        ---
        title: {$this->yaml($title)}
        description: Main Presentation
        author: Slide Announcer
        theme: revelation_dark.css
        stylesheet: style.css
        created: '{$date}'
        newSlideOnHeading: false
        config:
          transition: fade
          height: 540
          maxScale: 4
          controls: false
          progress: false
          slideNumber: true
          showSlideNumber: speaker
          hashOneBasedIndex: true
          hash: true
        confidence: {}
        version: 1.0.13
        ---

        YAML;
    }

    /** A YAML scalar: plain when trivially safe, otherwise single-quoted. */
    private function yaml(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.-]*$/', $value) && ! preg_match('/^(true|false|null|yes|no|on|off|[0-9.]+)$/i', $value)) {
            return $value;
        }

        return "'" . str_replace("'", "''", preg_replace('/\s+/', ' ', $value)) . "'";
    }

    /** The first slide's stored composite thumbnail, else its primary's. */
    private function thumbnailPath(Collection $slides): ?string
    {
        $first = $slides->first(fn (Slide $s) => $s->primaryMedia);
        if (! $first) {
            return null;
        }

        $disk = Storage::disk('public');
        foreach ([$first->overlay_thumbnail_path, $first->primaryMedia->thumbnail_path] as $rel) {
            if ($rel && $disk->exists($rel)) {
                return $disk->path($rel);
            }
        }

        return null;
    }

    /**
     * manifest.json: every file in the package (sorted by name) with size,
     * modified time and SHA-1 — except the manifest itself, which has no
     * hash and whose size is settled by iterating, as it counts its own.
     */
    private function manifest(array $files, array $generated): string
    {
        $now     = $this->iso(microtime(true));
        $entries = [];

        foreach ($files as $name => $path) {
            $entries[$name] = [
                'filename' => $name,
                'size'     => filesize($path),
                'modified' => $this->iso(filemtime($path)),
                'sha1'     => sha1_file($path),
            ];
        }
        foreach ($generated as $name => $contents) {
            $entries[$name] = [
                'filename' => $name,
                'size'     => strlen($contents),
                'modified' => $now,
                'sha1'     => sha1($contents),
            ];
        }

        $entries['manifest.json'] = ['filename' => 'manifest.json', 'size' => 0, 'modified' => $now];
        ksort($entries, SORT_STRING);

        $document = [
            'appVersion'      => self::APP_VERSION,
            'savedAt'         => $now,
            'markdownFiles'   => ['presentation.md'],
            'presentationId'  => (string) Str::uuid(),
        ];

        $encode = function () use (&$document, &$entries) {
            $document['files'] = array_values($entries);

            $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // PHP indents four spaces; the editor writes two.
            return preg_replace_callback('/^ +/m', fn ($m) => str_repeat(' ', intdiv(strlen($m[0]), 2)), $json);
        };

        $json = $encode();
        for ($i = 0; $i < 5; $i++) {
            $entries['manifest.json']['size'] = strlen($json);
            $next = $encode();
            if (strlen($next) === strlen($json)) {
                break;
            }
            $json = $next;
        }

        return $next ?? $json;
    }

    private function iso(float|int $timestamp): string
    {
        $dt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $timestamp), new \DateTimeZone('UTC'));

        return $dt->format('Y-m-d\TH:i:s.') . substr($dt->format('u'), 0, 3) . 'Z';
    }
}
