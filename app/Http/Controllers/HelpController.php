<?php

namespace App\Http\Controllers;

use App\Support\Help\HelpLibrary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * In-app help. Public (like About) so signed-out visitors can read it; a
 * topic's `audience` front-matter hides the signed-in-only and admin topics.
 */
class HelpController extends Controller
{
    public function index(Request $request): Response
    {
        [$library, $locale, $explicit] = $this->context($request);

        return Inertia::render('Help/Index', [
            'topics' => $library->topics($locale, $request->user()),
            ...$this->languageProps($library, $locale, $explicit),
        ]);
    }

    public function show(Request $request, string $topic): Response
    {
        [$library, $locale, $explicit] = $this->context($request);

        $found = $library->find($topic, $locale, $request->user(), $explicit ? '?lang='.$explicit : '');
        abort_if($found === null, 404);

        return Inertia::render('Help/Show', [
            'topic' => $found,
            'topics' => $library->topics($locale, $request->user()),
            ...$this->languageProps($library, $locale, $explicit),
        ]);
    }

    /** A screenshot, video or caption track used by a help topic. */
    public function media(string $path): BinaryFileResponse
    {
        $file = HelpLibrary::make()->mediaFile($path);
        abort_if($file === null, 404);

        // BinaryFileResponse honours Range requests, so videos can be scrubbed.
        return response()->file($file, [
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{0: HelpLibrary, 1: string, 2: ?string} library, language to show, explicit ?lang= choice */
    private function context(Request $request): array
    {
        $library = HelpLibrary::make();
        $explicit = $request->query('lang');
        $explicit = is_string($explicit) && in_array($explicit, $library->locales(), true) ? $explicit : null;

        $locale = $library->resolveLocale(
            $explicit,
            $request->getPreferredLanguage($library->locales()),
        );

        return [$library, $locale, $explicit];
    }

    private function languageProps(HelpLibrary $library, string $locale, ?string $explicit): array
    {
        return [
            'locale' => $locale,
            'langQuery' => $explicit ? ['lang' => $explicit] : (object) [],
            'languages' => $library->availableLocales(),
        ];
    }
}
