<?php

namespace App\Services;

use App\Models\Slide;
use App\Models\Widget;
use App\Services\Widgets\OverlayWidgets;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Builds a "REVELation Snapshot Presenter" package (a `.revel` file, media type
 * application/vnd.revelation.presentation+zip): a zip holding each
 * slide's base image/video and (separately) its overlay, keyed by slide
 * number, plus a presentation.md that stacks them (the base marked with the
 * special "fill:background" alt text, the overlay as "fill"), a stub stylesheet,
 * a thumbnail of the first slide and a manifest.json listing every file
 * with its size, mtime and SHA-1.
 *
 * The output must conform to the .revel format spec:
 * https://github.com/fiforms/revelation-electron-wrapper/blob/main/doc/dev/REVEL_FORMAT.md
 * (archive root is the presentation folder, entry names relative with no "..",
 * no executable/script content, manifest.json sorted with SHA-1s and no hash
 * for itself, appVersion set to the REVELation release implemented). Re-check
 * it when changing the layout, manifest or the file types we package.
 *
 * A slide's overlay widgets follow its overlay as `:widget:` YAML blocks.
 */
class RevelationSnapshotBuilder
{
    private const APP_VERSION = '1.0.13';

    /** The overlay editor's canvas, which widget placements are measured in. */
    private const CANVAS_W = 1920;
    private const CANVAS_H = 1080;

    public function __construct(private OverlayWidgets $widgets)
    {
    }

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
     * caller deletes it. Slides without media are skipped. $location is
     * the screen location widgets fall back to (see WidgetLocation); it's
     * written into the block of each widget whose manifest declares
     * "usesLocation", so e.g. a weather widget with a blank ZIP still gets one.
     * $slideDelaySeconds is the auto-advance time (config default when null).
     */
    public function build(Collection $slides, string $title, ?array $location = null, ?int $slideDelaySeconds = null): string
    {
        $disk  = Storage::disk('public');
        $total = $slides->count();

        /** @var array<string, string> entry name => absolute source path */
        $files    = [];
        $sections = [];
        $position = 0;

        foreach ($slides as $slide) {
            // A slide may have no image: it is exported if it has an overlay
            // (with its widgets); one with neither has nothing to show.
            $media   = $slide->primaryMedia;
            $overlay = $slide->overlayMedia;
            $hasBase    = $media && file_exists($disk->path($media->disk_path));
            $hasOverlay = $overlay && file_exists($disk->path($overlay->disk_path));
            if (! $hasBase && ! $hasOverlay) {
                continue;
            }
            $position++;

            if ($hasBase) {
                $baseName = self::entryName($position, $total, $media->downloadName());
                $files[$baseName] = $disk->path($media->disk_path);
            }
            if (! $hasOverlay) {
                $sections[] = $this->image('fill', $baseName);
                continue;
            }

            $ext         = pathinfo($overlay->disk_path, PATHINFO_EXTENSION) ?: 'svg';
            $overlayName = self::entryName($position, $total, "overlay.{$ext}");
            $files[$overlayName] = $disk->path($overlay->disk_path);

            $section = $hasBase
                ? $this->image('fill:background', $baseName) . "\n\n" . $this->image('fill', $overlayName)
                : $this->image('fill', $overlayName);
            $installed = Widget::enabledBySlug();
            foreach ($this->widgets->forDevice($overlay) as $placement) {
                $usesLocation = (bool) ($installed[$placement['widget']]->manifest['usesLocation'] ?? false);
                $section .= "\n\n" . $this->widgetBlock($placement, $location, $usesLocation);
            }
            $sections[] = $section;
        }

        if (! $files) {
            throw new RuntimeException('No slides to export.');
        }

        $generated = [
            'presentation.md' => $this->frontMatter($title, $slideDelaySeconds ?? (int) config('slides.slide_delay_seconds')) . implode("\n\n---\n\n", $sections),
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

    /**
     * A `:widget:` block for one saved placement (enabled widgets only, via
     * forDevice()): the widget's slug as name, its box as fractions (0-1) of
     * the screen width/height so it's independent of canvas size, and its
     * parameters. Opacity is only written when the widget isn't fully opaque.
     */
    private function widgetBlock(array $p, ?array $location, bool $usesLocation): string
    {
        $lines = [
            ':widget:',
            '  name: ' . $this->yaml((string) $p['widget']),
            '  position:',
            '    x: ' . $this->yamlScalar($p['x'] / self::CANVAS_W),
            '    y: ' . $this->yamlScalar($p['y'] / self::CANVAS_H),
            '  size:',
            '    w: ' . $this->yamlScalar($p['w'] / self::CANVAS_W),
            '    h: ' . $this->yamlScalar($p['h'] / self::CANVAS_H),
        ];
        if (($p['opacity'] ?? 1) < 1) {
            $lines[] = '  opacity: ' . $this->yamlScalar($p['opacity']);
        }
        if ($location && $usesLocation) {
            $lines[] = '  location:';
            $lines[] = '    name: ' . $this->yamlScalar((string) $location['name']);
            $lines[] = '    latitude: ' . $this->yamlScalar((float) $location['latitude']);
            $lines[] = '    longitude: ' . $this->yamlScalar((float) $location['longitude']);
        }
        if (! empty($p['params'])) {
            $lines[] = '  parameters:';
            foreach ($p['params'] as $key => $value) {
                $lines[] = '    ' . $key . ': ' . $this->yamlScalar($value);
            }
        }

        return implode("\n", $lines);
    }

    /** Any widget-parameter scalar (bool, null, number, string) as YAML. */
    private function yamlScalar(mixed $value): string
    {
        return match (true) {
            is_bool($value)  => $value ? 'true' : 'false',
            $value === null  => 'null',
            is_int($value)   => (string) $value,
            is_float($value) => rtrim(rtrim(sprintf('%.6F', $value), '0'), '.'),
            default          => $this->yaml((string) $value, multiline: true),
        };
    }

    private function frontMatter(string $title, int $slideDelaySeconds): string
    {
        $date      = now()->toDateString();
        $autoSlide = $slideDelaySeconds * 1000;

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
          loop: true
          autoSlide: {$autoSlide}
          autoSlideStoppable: false
        confidence: {}
        version: 1.0.13
        ---

        YAML;
    }

    /** A YAML scalar: plain when trivially safe, otherwise single-quoted. */
    private function yaml(string $value, bool $multiline = false): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.-]*$/', $value) && ! preg_match('/^(true|false|null|yes|no|on|off|[0-9.]+)$/i', $value)) {
            return $value;
        }

        // Free text may contain newlines, which a single-quoted scalar can't
        // carry; a JSON string is valid double-quoted YAML.
        if ($multiline) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return "'" . str_replace("'", "''", preg_replace('/\s+/', ' ', $value)) . "'";
    }

    /** The first slide's stored composite thumbnail, else its primary's. */
    private function thumbnailPath(Collection $slides): ?string
    {
        $first = $slides->first(fn (Slide $s) => $s->primaryMedia || $s->overlayMedia);
        if (! $first) {
            return null;
        }

        $disk = Storage::disk('public');
        foreach ([$first->overlay_thumbnail_path, $first->primaryMedia?->thumbnail_path] as $rel) {
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
