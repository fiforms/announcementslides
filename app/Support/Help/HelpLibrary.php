<?php

namespace App\Support\Help;

use App\Models\User;
use Illuminate\Support\Collection;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\DefaultAttributes\DefaultAttributesExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;

/**
 * The in-app help: Markdown files at <path>/<locale>/<topic>.md, each with a
 * small front-matter block (see resources/help/README.md). A topic missing from
 * the requested language falls back to the default language, so translations
 * can arrive one file at a time.
 */
class HelpLibrary
{
    /** Who may read a topic: its `audience` front-matter value. */
    public const AUDIENCES = ['everyone', 'users', 'managers', 'admin'];

    /** Files the media route will serve (no SVG: see the SVG security model in ARCHITECTURE.md). */
    public const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    public const VIDEO_EXTENSIONS = ['mp4', 'webm'];

    /** Caption tracks for videos. */
    public const TRACK_EXTENSIONS = ['vtt'];

    public function __construct(
        private readonly string $path,
        private readonly string $defaultLocale,
        private readonly array $locales,
    ) {}

    public static function make(): self
    {
        return new self(
            config('help.path'),
            config('help.default_locale'),
            config('help.locales'),
        );
    }

    public function locales(): array
    {
        return $this->locales;
    }

    /** Languages that have at least one topic, for the language switcher. */
    public function availableLocales(): array
    {
        return array_values(array_filter($this->locales, fn ($l) => $this->slugs($l)->isNotEmpty()));
    }

    /** Pick a supported language from an explicit choice, else the browser's preference. */
    public function resolveLocale(?string $explicit, ?string $preferred): string
    {
        foreach ([$explicit, $preferred] as $candidate) {
            if ($candidate && in_array($candidate, $this->locales, true)) {
                return $candidate;
            }
        }

        return $this->defaultLocale;
    }

    /**
     * The topics this user may read, in display order.
     *
     * @return Collection<int, array{slug:string,title:string,summary:string,group:string,order:int,fallback:bool}>
     */
    public function topics(string $locale, ?User $user): Collection
    {
        $slugs = $this->slugs($locale)->merge($this->slugs($this->defaultLocale))->unique();

        return $slugs
            ->map(fn ($slug) => $this->meta($slug, $locale))
            ->filter(fn ($meta) => $meta && $this->canRead($meta['audience'], $user))
            ->sortBy([['order', 'asc'], ['title', 'asc']])
            ->map(fn ($meta) => collect($meta)->except('audience')->all())
            ->values();
    }

    /**
     * One topic with its rendered body, or null when it does not exist or the
     * user may not read it.
     *
     * @param  string  $linkSuffix  appended to topic links inside the body (e.g. "?lang=es")
     */
    public function find(string $slug, string $locale, ?User $user, string $linkSuffix = ''): ?array
    {
        $meta = $this->meta($slug, $locale);

        if (! $meta || ! $this->canRead($meta['audience'], $user)) {
            return null;
        }

        $html = $this->render($meta['body'], $locale, $linkSuffix);

        return collect($meta)->except(['audience', 'body'])->put('html', $html)->all();
    }

    public function canRead(string $audience, ?User $user): bool
    {
        return match ($audience) {
            'users' => (bool) $user,
            'managers' => $user && ($user->isAdmin() || $user->adminEntities()->exists()),
            'admin' => $user && $user->isAdmin(),
            default => true,
        };
    }

    /** Topic slugs (file names) that exist for a language. */
    private function slugs(string $locale): Collection
    {
        if (! in_array($locale, $this->locales, true)) {
            return collect();
        }

        return collect(glob($this->path.'/'.$locale.'/*.md') ?: [])
            ->map(fn ($file) => basename($file, '.md'))
            ->filter(fn ($slug) => preg_match('/^[a-z0-9-]+$/', $slug))
            ->values();
    }

    /** Parse a topic's file in the best available language. */
    private function meta(string $slug, string $locale): ?array
    {
        if (! preg_match('/^[a-z0-9-]+$/', $slug)) {
            return null;
        }

        foreach (array_unique([$locale, $this->defaultLocale]) as $tryLocale) {
            $file = $this->path.'/'.$tryLocale.'/'.$slug.'.md';
            if (! in_array($tryLocale, $this->locales, true) || ! is_file($file)) {
                continue;
            }

            [$front, $body] = $this->splitFrontMatter(file_get_contents($file));
            $audience = in_array($front['audience'] ?? '', self::AUDIENCES, true) ? $front['audience'] : 'everyone';

            return [
                'slug' => $slug,
                'title' => $front['title'] ?? $slug,
                'summary' => $front['summary'] ?? '',
                'group' => $front['group'] ?? '',
                'order' => (int) ($front['order'] ?? 100),
                'audience' => $audience,
                'locale' => $tryLocale,
                'fallback' => $tryLocale !== $locale,
                'body' => $body,
            ];
        }

        return null;
    }

    /**
     * Split "---\nkey: value\n---\nbody". Only flat `key: value` lines are
     * supported; that is all a help topic needs.
     *
     * @return array{0: array<string,string>, 1: string}
     */
    private function splitFrontMatter(string $text): array
    {
        $text = str_replace("\r\n", "\n", ltrim($text, "\xEF\xBB\xBF"));

        if (! preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $text, $m)) {
            return [[], $text];
        }

        $front = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^([a-z_]+):\s*(.*?)\s*$/', $line, $kv)) {
                $front[$kv[1]] = trim($kv[2], "\"'");
            }
        }

        return [$front, $m[2]];
    }

    /**
     * Markdown to HTML. Raw HTML in a topic is dropped, unsafe links are
     * refused, and `help:<slug>` links become links to that topic.
     */
    private function render(string $markdown, string $locale, string $linkSuffix): string
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'heading_permalink' => ['insert' => 'none', 'id_prefix' => '', 'apply_id_to_heading' => true],
            'default_attributes' => [
                \League\CommonMark\Extension\CommonMark\Node\Inline\Link::class => [
                    'target' => static fn ($link) => str_starts_with($link->getUrl(), 'http') ? '_blank' : null,
                    'rel' => static fn ($link) => str_starts_with($link->getUrl(), 'http') ? 'noopener noreferrer' : null,
                ],
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new HeadingPermalinkExtension);
        $environment->addExtension(new DefaultAttributesExtension);

        $html = (new MarkdownConverter($environment))->convert($markdown)->getContent();

        $html = preg_replace_callback(
            '/href="help:([a-z0-9-]+)(#[^"]*)?"/',
            fn ($m) => 'href="'.e(url('/help/'.$m[1])).e($linkSuffix).($m[2] ?? '').'"',
            $html,
        );

        // ![alt](media:name.png) and ![alt](https://…/clip.mp4): images and videos.
        return preg_replace_callback(
            '/<img src="([^"]*)" alt="([^"]*)"[^>]*>/',
            fn ($m) => $this->renderMedia(html_entity_decode($m[1]), $m[2], $locale) ?? $m[0],
            $html,
        );
    }

    /** An <img>/<video> for a media reference, or null to leave the tag alone. */
    private function renderMedia(string $src, string $alt, string $locale): ?string
    {
        if (str_starts_with($src, 'media:')) {
            $path = $this->resolveMedia(substr($src, 6), $locale);
            $url = $path ? url('/help-media/'.$path) : null;
            $name = substr($src, 6);
        } elseif (preg_match('#^https://#i', $src)) {
            $url = $src;
            $name = parse_url($src, PHP_URL_PATH) ?: '';
        } else {
            return null; // anything else keeps the plain <img>
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($url === null) {
            return '<span class="help-media-missing">'.$alt.'</span>';
        }

        if (in_array($ext, self::VIDEO_EXTENSIONS, true)) {
            $track = isset($path) ? $this->resolveMedia(preg_replace('/\.[^.]+$/', '.vtt', $name), $locale) : null;

            return '<video controls preload="metadata" playsinline src="'.e($url).'" aria-label="'.$alt.'">'
                .($track ? '<track kind="captions" default srclang="'.e($locale).'" src="'.e(url('/help-media/'.$track)).'">' : '')
                .'</video>';
        }

        return '<img src="'.e($url).'" alt="'.$alt.'" loading="lazy">';
    }

    /**
     * Find a media file for a language: that language's own copy, then the
     * default language's, then the shared folder. Returns the path relative to
     * the help folder, or null.
     */
    private function resolveMedia(string $name, string $locale): ?string
    {
        foreach (array_unique([$locale, $this->defaultLocale, null]) as $folder) {
            $candidate = ($folder ? $folder.'/' : '').'media/'.$name;
            if ($this->mediaFile($candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Absolute path of a servable media file given its path under the help
     * folder (`media/x.png` or `<locale>/media/x.png`), or null. Strict about
     * path shape and extension so the route cannot read anything else.
     */
    public function mediaFile(string $path): ?string
    {
        $segments = explode('/', $path);
        $first = $segments[0] ?? '';
        $mediaAt = $first === 'media' ? 0 : (in_array($first, $this->locales, true) && ($segments[1] ?? '') === 'media' ? 1 : null);

        if ($mediaAt === null || count($segments) <= $mediaAt + 1) {
            return null;
        }

        foreach ($segments as $segment) {
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $segment)) {
                return null;
            }
        }

        $allowed = [...self::IMAGE_EXTENSIONS, ...self::VIDEO_EXTENSIONS, ...self::TRACK_EXTENSIONS];
        if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $allowed, true)) {
            return null;
        }

        $file = $this->path.'/'.$path;

        return is_file($file) ? $file : null;
    }
}
