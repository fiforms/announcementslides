<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesSlideMedia;
use App\Models\Entity;
use App\Models\GlobalShowTemplate;
use App\Models\Language;
use App\Models\Show;
use App\Models\Slide;
use App\Models\SlideMedia;
use App\Services\OverlayCompositor;
use App\Services\RevelationSnapshotBuilder;
use App\Services\VideoFrameExtractor;
use App\Support\NearbyEntities;
use App\Support\WidgetLocation;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\ShowFrame;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpPresentation\DocumentLayout;
use PhpOffice\PhpPresentation\Shape\Drawing\File as DrawingFile;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Slide\Background\Color as BackgroundColor;
use PhpOffice\PhpPresentation\Style\Color;
use ZipArchive;

class SlideController extends Controller
{
    use ManagesSlideMedia;

    public function index(Request $request, ?Slide $slide = null): Response
    {
        $languageCode = $request->query('language');
        $languageId = null;
        $entityId = $request->query('entity_id') ? (int) $request->query('entity_id') : null;
        $this->authorizeEntityView($request, $entityId);

        // If no language specified, try to detect from Accept-Language header (browser default)
        if (!$languageCode) {
            $acceptLanguage = $request->header('Accept-Language');
            if ($acceptLanguage) {
                // Extract language code (e.g., 'en' from 'en-US,en;q=0.9')
                preg_match('/^([a-z]{2})/', $acceptLanguage, $matches);
                $languageCode = $matches[1] ?? null;
            }
        }

        if ($languageCode) {
            $language = Language::where('abbreviation', $languageCode)->first();
            $languageId = $language?->id;
        }

        // Slide membership/order now lives entirely in show_slides: an entity
        // request plays that entity's Main show by default (or another of
        // its shows via ?show_id=, to preview it) and a request with no
        // entity plays the Global Board by default (or a globally-pushed
        // one-off "promo" show via ?promo=). Nearby-shared slides get into a
        // show via auto-add or a leader manually dragging them in — no
        // separate live union needed.
        $availableShows = [];
        $promo = null;

        if ($entityId) {
            $entity = Entity::findOrFail($entityId);
            $entityShows = Show::where('entity_id', $entityId)->orderByDesc('is_main')->orderBy('name')->get();
            $selectedShow = $this->requestedShow($request, $entityId) ?? $entity->mainShow();
            $showId = $selectedShow->id;
            $availableShows = $entityShows->map(fn ($s) => ['id' => $s->id, 'token' => $s->token, 'name' => $s->name, 'system' => $s->is_main ? 'main' : null])->all();
        } else {
            $globalBoard = Show::globalBoard();

            // One-off distributed shows (e.g. a special promo video) don't have
            // a single show row — each entity got its own copy — so they're
            // addressed by template instead (a stable, hot-linkable ?promo=).
            // A link to a promo that has since expired and been swept just
            // falls back to the Global Board.
            $promo = $this->requestedPromo($request);
            $selectedShow = $promo ? null : ($this->requestedShow($request, null) ?? $globalBoard);
            $showId = $selectedShow?->id;

            $availableShows = collect([['id' => $globalBoard->id, 'token' => $globalBoard->token, 'name' => 'Announcements', 'system' => 'global']])
                ->concat(
                    GlobalShowTemplate::live()->orderBy('name')->get()->map(fn ($t) => [
                        'id' => null,
                        'token' => null,
                        'promo' => $t->token,
                        'name' => $t->name,
                        'system' => null,
                    ])
                )->all();
        }

        // Shows hold every language, so the language selector is purely a
        // display filter (untagged slides always show).
        // Slides with no image and no overlay are invisible placeholders kept
        // in the show for editing; they aren't listed or played here.
        $slidesQuery = ($promo ? $promo->slidesQuery() : Slide::orderedInShow($showId))
            ->with(['primaryMedia', 'overlayMedia', 'media'])
            ->current()
            ->withContent()
            ->language($languageId);
        $slides = $slidesQuery->get()->map(fn ($s) => $this->slideResource($s));

        // The show's frame (background under, overlay + widgets over every
        // slide) for the slideshow. Only an entity's own show has one, and
        // its widgets' data is only readable by that entity's members, so
        // it goes to them (and site admins) alone.
        $user = $request->user();
        $frameShow = $entityId ? Show::find($showId) : null;
        $frame = $frameShow && $frameShow->entity_id === $entityId
            && $user && in_array($entityId, $user->viewableEntityIds())
            ? app(ShowFrame::class)->forPlayer($frameShow)
            : null;

        $languages = Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']);

        // Deep link to a single slide (e.g. /slides/{slide}): render the same
        // dashboard, plus that slide's data so the frontend can open its
        // lightbox on load. Only if it's actually visible to this viewer —
        // it may belong to a different show/entity than the one loaded above,
        // and drafts/pending/unreleased slides never open from a link.
        $initialSlide = null;
        if ($slide && Slide::released()->visibleToUser($request->user())->hidingArchivedLocalFrom($request->user())->whereKey($slide->id)->exists()) {
            $slide->load(['primaryMedia', 'overlayMedia', 'media']);
            $initialSlide = $this->slideResource($slide);
        }

        return Inertia::render('Slides/Index', [
            'slides' => $slides,
            'languages' => $languages,
            'selectedLanguage' => $languageCode,
            'entityId' => $entityId,
            'showToken' => $selectedShow?->token,
            'promoToken' => $promo?->token,
            'frame' => $frame,
            'availableShows' => $availableShows,
            'initialSlide' => $initialSlide,
        ]);
    }

    public function archive(Request $request): Response
    {
        $languageCode = $request->query('language');
        $languageId = null;
        $entityId = $request->query('entity_id') ? (int) $request->query('entity_id') : null;
        $user = $request->user();

        // Archives exist per church and are for its leaders only — not
        // followers, and not site admins unless they lead it too. There is no
        // global archive page.
        abort_if($entityId === null, 404);
        if (! $user) {
            throw new AuthenticationException();
        }
        abort_unless(in_array($entityId, $user->leaderEntityIds(), true), 403);

        // If no language specified, try to detect from Accept-Language header (browser default)
        if (!$languageCode) {
            $acceptLanguage = $request->header('Accept-Language');
            if ($acceptLanguage) {
                // Extract language code (e.g., 'en' from 'en-US,en;q=0.9')
                preg_match('/^([a-z]{2})/', $acceptLanguage, $matches);
                $languageCode = $matches[1] ?? null;
            }
        }

        if ($languageCode) {
            $language = Language::where('abbreviation', $languageCode)->first();
            $languageId = $language?->id;
        }

        $query = Slide::with(['primaryMedia', 'overlayMedia', 'media'])->archived()->language($languageId);

        $query->where(fn ($q) => $q->whereNull('entity_id')->orWhere('entity_id', $entityId));

        $query->orderByDesc('expires_at');

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%"));
        }

        $slides = $query->paginate(24)->withQueryString()->through(fn ($s) => $this->slideResource($s));

        $languages = Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']);

        return Inertia::render('Slides/Archive', [
            'slides' => $slides,
            'languages' => $languages,
            'search' => $search,
            'selectedLanguage' => $languageCode,
            'entityId' => $entityId,
            'isAdmin' => true,
        ]);
    }

    /**
     * Restore an archived slide by clearing its expiry — the entity-leader
     * counterpart to LocalSlideController::unarchive(), reachable from the
     * /archive page's lightbox rather than a per-entity slide list. Scoped to
     * the slide's own entity (never a global slide, which is an admin's
     * call), and to that entity's admins/site admins — same bar as every
     * other entity-leader slide action.
     */
    public function unarchive(Request $request, Slide $slide)
    {
        $user = $request->user();
        $entityId = $slide->entity_id;

        abort_unless($entityId && ($user->isAdmin() || $user->isEntityAdmin($entityId)), 403);

        $slide->update(['expires_at' => null]);

        return back()->with('success', 'Slide restored.');
    }

    /**
     * Downloads the slide as viewers see it: the overlay (if any) burned
     * into the primary image. Slides with no overlay (or a video/failed
     * flatten) download the primary file as-is.
     */
    public function download(Request $request, Slide $slide, OverlayCompositor $compositor)
    {
        abort_unless($this->canDownload($request, Slide::released(), $slide), 404);

        $media = $slide->primaryMedia;
        abort_unless($media, 404);

        $composite = $this->flattenSlide($slide, $media, $compositor);
        if ($composite) {
            return response()->download($composite, $this->jpgName($media->titledDownloadName()), [
                'Content-Type' => 'image/jpeg',
            ])->deleteFileAfterSend(true);
        }

        return Storage::disk('public')->download($media->disk_path, $media->titledDownloadName());
    }

    /**
     * Download any of a slide's attached media (overlay, flyer PDF, social
     * image, ...), not just the primary file — used by the lightbox's
     * per-attachment download buttons. Gated the same way any other
     * public-facing slide content is: the slide must be current.
     */
    public function downloadMedia(Request $request, Slide $slide, SlideMedia $media)
    {
        abort_unless($media->slide_id === $slide->id, 404);
        abort_unless($this->canDownload($request, Slide::current(), $slide), 404);

        // The bare base image of an overlaid slide is the 'Background' (the
        // composited slide is the plain download); other types name themselves.
        $suffix = match ($media->media_type) {
            'slide'            => $slide->overlayMedia ? 'Background' : '',
            'slide-overlay'    => 'Overlay',
            'color-flyer'      => 'Color Flyer',
            'easy-print-flyer' => 'Easy-Print Flyer',
            'social-media-image' => 'Social Media Image',
            default            => '',
        };

        return Storage::disk('public')->download($media->disk_path, $media->titledDownloadName($suffix));
    }

    public function downloadZip(Request $request, OverlayCompositor $compositor)
    {
        $slides = $this->resolveDownloadSlides($request);
        $slides->load('overlayMedia');

        if ($slides->isEmpty()) {
            abort(404);
        }

        $zip     = new ZipArchive();
        $tmpFile = tempnam(sys_get_temp_dir(), 'slides_');
        unlink($tmpFile);

        if ($zip->open($tmpFile, ZipArchive::CREATE) !== true) {
            abort(500, 'Could not create zip archive.');
        }

        $position   = 0;
        $tempImages = [];
        foreach ($slides as $slide) {
            $media = $slide->primaryMedia;
            if (! $media) {
                continue;
            }
            $fullPath = Storage::disk('public')->path($media->disk_path);
            if (! file_exists($fullPath)) {
                continue;
            }
            $name = $media->titledDownloadName();

            // Burn the overlay into the image so the zip holds the slide as
            // viewers see it (falling back to the bare file).
            $composite = $this->flattenSlide($slide, $media, $compositor);
            if ($composite) {
                $tempImages[] = $composite;
                $fullPath = $composite;
                $name = $this->jpgName($name);
            }

            $zip->addFile($fullPath, RevelationSnapshotBuilder::entryName(++$position, $slides->count(), $name));
        }

        // The archive reads the files at close(), so they go afterwards.
        try {
            $zip->close();
        } finally {
            foreach ($tempImages as $path) {
                @unlink($path);
            }
        }

        return response()->download($tmpFile, 'announcement-slides.zip', [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Flattens the slide's overlay onto its primary image into a temp JPEG
     * (full base resolution) and returns its path, or null if there's no
     * overlay, the primary isn't an image, or flattening failed. The caller
     * owns the file and must delete it.
     */
    private function flattenSlide(Slide $slide, SlideMedia $media, OverlayCompositor $compositor): ?string
    {
        $overlay = $slide->overlayMedia;
        if (! $overlay || ! $media->isImage()) {
            return null;
        }

        $path = sys_get_temp_dir() . '/slide-composite-' . Str::uuid() . '.jpg';
        $ok   = $compositor->flatten(Storage::disk('public')->path($media->disk_path), $overlay, $path, quality: 92);

        return $ok ? $path : null;
    }

    private function jpgName(string $name): string
    {
        if (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['jpg', 'jpeg'], true)) {
            return $name;
        }

        return pathinfo($name, PATHINFO_FILENAME) . '.jpg';
    }

    /**
     * REVELation Snapshot Presenter export: base images/videos and overlays
     * as separate, slide-numbered files plus presentation.md and manifest.json
     * (see RevelationSnapshotBuilder).
     */
    public function downloadRevelation(Request $request, RevelationSnapshotBuilder $builder)
    {
        $slides = $this->resolveDownloadSlides($request);
        $slides->load('overlayMedia');

        if ($slides->isEmpty()) {
            abort(404);
        }

        // resolveDownloadSlides() above has already gated ?entity_id=.
        $show  = $this->requestedShow($request, $request->query('entity_id') ? (int) $request->query('entity_id') : null);
        $title = $show?->name ?: 'Announcement Slides';

        // Where widgets with no location of their own should point: the
        // show's church, else the requested entity (?entity_id=), else the site
        // default (the same chain the live screens use).
        $location = $show?->entity_id
            ? WidgetLocation::for($show->entity)
            : WidgetLocation::forRequest($request);

        $tmpFile = $builder->build($slides, $title, $location, $request->user()?->slideDelaySeconds());

        // "Main Show" -> main_show.revel; non-letters/digits collapse to underscores.
        $slug = trim(preg_replace('/[^\p{L}\p{N}]+/u', '_', mb_strtolower($title)), '_') ?: 'announcement_slides';

        return response()->download($tmpFile, "{$slug}.revel", [
            'Content-Type' => 'application/vnd.revelation.presentation+zip',
        ])->deleteFileAfterSend(true);
    }

    public function downloadPowerPoint(Request $request, OverlayCompositor $compositor, VideoFrameExtractor $frames)
    {
        $slides = $this->resolveDownloadSlides($request);
        $slides->load('overlayMedia');

        if ($slides->isEmpty()) {
            abort(404);
        }

        $presentation = new PhpPresentation();
        $presentation->getProperties()->setTitle('Announcement Slides');
        $presentation->getLayout()->setDocumentLayout(DocumentLayout::LAYOUT_SCREEN_16X9);
        $presentation->removeSlideByIndex(0);

        // Slide dimensions (in presentation units)
        $slideWidth = 1920;
        $slideHeight = 1080;

        // Video frames and flattened overlay composites; the writer reads
        // them at save(), so they're only removed afterwards.
        $tempImages = [];
        $disk = Storage::disk('public');

        foreach ($slides as $slide) {
            // A slide may have no image: its overlay (and widget stand-ins) is
            // then drawn on black. With neither, there's nothing to export.
            $media = $slide->primaryMedia;
            $fullPath = $media ? $disk->path($media->disk_path) : null;

            // Videos aren't embedded yet — export a full-size still frame
            // instead, falling back to the small listing thumbnail if ffmpeg
            // can't produce one (and drawing no base if there's neither).
            if ($media?->isVideo()) {
                $framePath = sys_get_temp_dir() . '/slide-frame-' . Str::uuid() . '.jpg';
                if ($frames->extract($fullPath, $framePath, logContext: ['slide_media_id' => $media->id])) {
                    $tempImages[] = $framePath;
                    $fullPath = $framePath;
                } elseif ($media->thumbnail_path) {
                    $fullPath = $disk->path($media->thumbnail_path);
                } else {
                    $fullPath = null;
                }
            }

            // PowerPoint has no notion of our overlay layer, so burn it into
            // a single full-slide JPEG. If flattening fails (e.g. an SVG
            // overlay without rsvg-convert), export the base alone.
            if ($slide->overlayMedia) {
                $compositePath = sys_get_temp_dir() . '/slide-composite-' . Str::uuid() . '.jpg';
                if ($compositor->flatten($fullPath, $slide->overlayMedia, $compositePath, $slideWidth, $slideHeight, 92)) {
                    $tempImages[] = $compositePath;
                    $fullPath = $compositePath;
                }
            }

            if ($fullPath && file_exists($fullPath)) {
                $newSlide = $presentation->createSlide();

                // Set black background
                $oBkgColor = new BackgroundColor();
                $oBkgColor->setColor(new Color('FF000000'));
                $newSlide->setBackground($oBkgColor);

                $image = getimagesize($fullPath);
                if ($image) {
                    $imgWidth = $image[0];
                    $imgHeight = $image[1];

                    // Calculate scaling to fit image in slide while maintaining aspect ratio
                    $scale = min($slideWidth / $imgWidth, $slideHeight / $imgHeight);
                    $newWidth = $imgWidth * $scale;
                    $newHeight = $imgHeight * $scale;
                    $offsetX = ($slideWidth - $newWidth) / 2;
                    $offsetY = ($slideHeight - $newHeight) / 2;

                    $shape = new DrawingFile();
                    $shape->setPath($fullPath)
                        ->setHeight($newHeight / 2)
                        ->setWidth($newWidth / 2)
                        ->setOffsetX($offsetX / 2)
                        ->setOffsetY($offsetY / 2);
                    $newSlide->addShape($shape);
                }
            }
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'slides_');
        $oWriterPPTX = \PhpOffice\PhpPresentation\IOFactory::createWriter($presentation, 'PowerPoint2007');
        try {
            $oWriterPPTX->save($tmpFile);
        } finally {
            foreach ($tempImages as $path) {
                @unlink($path);
            }
        }

        return response()->download($tmpFile, 'announcement-slides.pptx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ])->deleteFileAfterSend(true);
    }

    /**
     * A show's exact slide set/order (?show=<token>, or ?promo=<token>, the
     * primary download path), or the legacy ad-hoc-selection path (?ids= slide
     * tokens, used from the global Slides/Index.vue browsing page, which has no
     * single show concept).
     */
    private function resolveDownloadSlides(Request $request)
    {
        $entityId = $request->query('entity_id') ? (int) $request->query('entity_id') : null;
        $this->authorizeEntityView($request, $entityId);
        $languageCode = $request->query('language');
        $languageId = null;

        if ($languageCode) {
            $language = Language::where('abbreviation', $languageCode)->first();
            $languageId = $language?->id;
        }

        if ($request->query('promo')) {
            return ($this->requestedPromo($request) ?? abort(404))->slidesQuery()
                ->with('primaryMedia')->current()->language($languageId)->get();
        }

        if ($request->query('show')) {
            $show = $this->requestedShow($request, $entityId) ?? abort(404);

            // Same language rule as the dashboard listing, so a download
            // contains exactly the slides the UI shows.
            return Slide::with('primaryMedia')->orderedInShow($show->id)->current()->language($languageId)->get();
        }

        $ids = $request->query('ids');

        $query = Slide::with('primaryMedia')
            ->current()
            ->visibleToUser($request->user())
            ->language($languageId)
            ->orderByDesc('created_at');

        if ($ids) {
            $query->whereIn('token', array_filter(explode(',', $ids)));
        }

        return $query->get();
    }

    /** Whether $slide is in $query and the viewer may see it (site admins see any entity's). */
    private function canDownload(Request $request, $query, Slide $slide): bool
    {
        $user = $request->user();
        if (! $user?->isAdmin()) {
            $query->visibleToUser($user)->hidingArchivedLocalFrom($user);
        }

        return $query->whereKey($slide->id)->exists();
    }

    /**
     * An entity's shows (and the local slides in them) are private to the
     * people who lead or follow that church — site admins included, who
     * subscribe like anyone else (their oversight tools are the admin console).
     * Guests are sent to log in, anyone else gets a 403 (before the entity is
     * even looked up, so ids can't be probed). Global content stays public.
     */
    private function authorizeEntityView(Request $request, ?int $entityId): void
    {
        if ($entityId === null) {
            return;
        }

        $user = $request->user();
        if (! $user) {
            throw new AuthenticationException();
        }

        abort_unless(in_array($entityId, $user->viewableEntityIds(), true), 403);
    }

    /**
     * The show named by ?show=<token>, only if it belongs to the requested
     * entity (or is a global show when there is none) — a token for some other
     * church's show never plays under this page. Null when absent or no match.
     */
    private function requestedShow(Request $request, ?int $entityId): ?Show
    {
        $token = $request->query('show');

        return is_string($token) && $token !== ''
            ? Show::where('token', $token)->where('entity_id', $entityId)->first()
            : null;
    }

    /** The promo named by ?promo=<token>, or null (a swept/unknown promo falls back to the Global Board). */
    private function requestedPromo(Request $request): ?GlobalShowTemplate
    {
        $token = $request->query('promo');

        return is_string($token) && $token !== '' ? GlobalShowTemplate::where('token', $token)->first() : null;
    }

    private function slideResource(Slide $slide): array
    {
        return [
            'id'                => $slide->id,
            'token'             => $slide->token,
            'title'             => $slide->title,
            'notes'             => $slide->notes,
            'text_description'  => $slide->text_description,
            'link'              => $slide->link,
            'video_playback_mode' => $slide->video_playback_mode,
            'mime_type'         => $slide->mime_type,
            'file_url'          => $slide->file_url,
            'thumbnail_url'     => $slide->thumbnail_url,
            'overlay_url'       => $slide->overlay_url,
            'overlay_mime_type' => $slide->overlay_mime_type,
            'overlay_widgets'   => $slide->overlay_widgets,
            'publish_at'        => $slide->publish_at?->toIso8601String(),
            'expires_at'        => $slide->expires_at?->toIso8601String(),
            'original_filename' => $slide->original_filename,
            'validation_issues' => $slide->validation_issues,
            'validation_status' => $slide->validation_status,
            'entity_id'         => $slide->entity_id,
            'media'             => $this->mediaResource($slide),
        ];
    }
}
