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
use App\Services\VideoFrameExtractor;
use App\Support\NearbyEntities;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        // one-off show via ?show_id=). Nearby-shared slides get into a show
        // via auto-add or a leader manually dragging them in — no separate
        // live union needed.
        $availableShows = [];

        if ($entityId) {
            $entity = Entity::findOrFail($entityId);
            $entityShows = Show::where('entity_id', $entityId)->orderByDesc('is_main')->orderBy('name')->get();
            $showId = $request->query('show_id')
                ? (int) $request->query('show_id')
                : $entity->mainShow()->id;
            $availableShows = $entityShows->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'system' => $s->is_main ? 'main' : null])->all();
        } else {
            $globalBoard = Show::globalBoard();
            $showId = $request->query('show_id') ? (int) $request->query('show_id') : $globalBoard->id;

            // One-off distributed shows (e.g. a special promo video) don't have
            // a single show row — each entity got its own copy — so pick one
            // representative copy per template purely so an anonymous/global
            // viewer can preview the same content.
            $availableShows = collect([['id' => $globalBoard->id, 'name' => 'Announcements', 'system' => 'global']])
                ->concat(
                    GlobalShowTemplate::has('shows')->get()->map(fn ($t) => [
                        'id' => $t->shows()->first()->id,
                        'name' => $t->name,
                        'system' => null,
                    ])
                )->all();
        }

        // Shows hold every language, so the language selector is purely a
        // display filter (untagged slides always show).
        $slidesQuery = Slide::with(['primaryMedia', 'overlayMedia', 'media'])->orderedInShow($showId)->current()
            ->language($languageId);
        $slides = $slidesQuery->get()->map(fn ($s) => $this->slideResource($s));

        $languages = Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']);

        // Deep link to a single slide (e.g. /slides/{slide}): render the same
        // dashboard, plus that slide's data so the frontend can open its
        // lightbox on load. Only if it's actually visible to this viewer —
        // it may belong to a different show/entity than the one loaded above.
        $initialSlide = null;
        if ($slide && Slide::visibleToUser($request->user())->whereKey($slide->id)->exists()) {
            $slide->load(['primaryMedia', 'overlayMedia', 'media']);
            $initialSlide = $this->slideResource($slide);
        }

        return Inertia::render('Slides/Index', [
            'slides' => $slides,
            'languages' => $languages,
            'selectedLanguage' => $languageCode,
            'entityId' => $entityId,
            'showId' => $showId,
            'availableShows' => $availableShows,
            'initialSlide' => $initialSlide,
        ]);
    }

    public function archive(Request $request): Response
    {
        $languageCode = $request->query('language');
        $languageId = null;
        $entityId = $request->query('entity_id') ? (int) $request->query('entity_id') : null;

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

        if ($entityId) {
            $query->where(fn ($q) => $q->whereNull('entity_id')->orWhere('entity_id', $entityId));
        } else {
            $query->visibleToUser($request->user());
        }

        $query->orderByDesc('expires_at');

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%"));
        }

        $slides = $query->paginate(24)->withQueryString()->through(fn ($s) => $this->slideResource($s));

        $languages = Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']);

        $user = $request->user();
        $isAdmin = $entityId && $user && ($user->isAdmin() || $user->isEntityAdmin($entityId));

        return Inertia::render('Slides/Archive', [
            'slides' => $slides,
            'languages' => $languages,
            'search' => $search,
            'selectedLanguage' => $languageCode,
            'entityId' => $entityId,
            'isAdmin' => $isAdmin,
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
    public function download(Slide $slide, OverlayCompositor $compositor)
    {
        abort_unless(
            $slide->status === 'published',
            404
        );

        $media = $slide->primaryMedia;
        abort_unless($media, 404);

        $composite = $this->flattenSlide($slide, $media, $compositor);
        if ($composite) {
            return response()->download($composite, $this->jpgName($media->downloadName()), [
                'Content-Type' => 'image/jpeg',
            ])->deleteFileAfterSend(true);
        }

        return Storage::disk('public')->download($media->disk_path, $media->downloadName());
    }

    /**
     * Download any of a slide's attached media (overlay, flyer PDF, social
     * image, ...), not just the primary file — used by the lightbox's
     * per-attachment download buttons. Gated the same way any other
     * public-facing slide content is: the slide must be current.
     */
    public function downloadMedia(Slide $slide, SlideMedia $media)
    {
        abort_unless($media->slide_id === $slide->id, 404);
        abort_unless(Slide::current()->whereKey($slide->id)->exists(), 404);

        return Storage::disk('public')->download($media->disk_path, $media->downloadName());
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
            $name = $media->downloadName();

            // Burn the overlay into the image so the zip holds the slide as
            // viewers see it (falling back to the bare file).
            $composite = $this->flattenSlide($slide, $media, $compositor);
            if ($composite) {
                $tempImages[] = $composite;
                $fullPath = $composite;
                $name = $this->jpgName($name);
            }

            $zip->addFile($fullPath, $this->zipEntryName(++$position, $slides->count(), $name));
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
     * The name a slide gets inside the zip: a zero-padded sequence number
     * (so extracting gives the deck in show order, and no two names can
     * collide) plus the filename with anything outside letters, digits,
     * spaces and . _ - ( ) replaced by underscores. Unicode letters stay,
     * so accented names survive.
     */
    private function zipEntryName(int $position, int $total, string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext  = pathinfo($name, PATHINFO_EXTENSION);

        $clean = fn (string $part) => trim(preg_replace('/[^\p{L}\p{N} ._()-]+/u', '_', $part), ' ._');
        $base  = $clean($base) ?: 'slide';
        $ext   = $clean($ext);

        $width = max(3, strlen((string) $total));

        return sprintf('%0' . $width . 'd_%s%s', $position, $base, $ext === '' ? '' : '.' . $ext);
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
            $media = $slide->primaryMedia;
            if (! $media) {
                continue;
            }
            $fullPath = $disk->path($media->disk_path);

            // Videos aren't embedded yet — export a full-size still frame
            // instead, falling back to the small listing thumbnail if ffmpeg
            // can't produce one (and skipping the slide if there's neither).
            if ($media->isVideo()) {
                $framePath = sys_get_temp_dir() . '/slide-frame-' . Str::uuid() . '.jpg';
                if ($frames->extract($fullPath, $framePath, logContext: ['slide_media_id' => $media->id])) {
                    $tempImages[] = $framePath;
                    $fullPath = $framePath;
                } elseif ($media->thumbnail_path) {
                    $fullPath = $disk->path($media->thumbnail_path);
                } else {
                    continue;
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

            if (file_exists($fullPath)) {
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
     * A show's exact slide set/order (?show_id=, the primary download path),
     * or the legacy ad-hoc-selection path (?ids=, used from the global
     * Slides/Index.vue browsing page, which has no single show concept).
     */
    private function resolveDownloadSlides(Request $request)
    {
        $showId = $request->query('show_id');
        $languageCode = $request->query('language');
        $languageId = null;

        if ($languageCode) {
            $language = Language::where('abbreviation', $languageCode)->first();
            $languageId = $language?->id;
        }

        if ($showId) {
            // Same language rule as the dashboard listing, so a download
            // contains exactly the slides the UI shows.
            return Slide::with('primaryMedia')->orderedInShow((int) $showId)->current()->language($languageId)->get();
        }

        $ids = $request->query('ids');

        $query = Slide::with('primaryMedia')
            ->current()
            ->visibleToUser($request->user())
            ->language($languageId)
            ->orderByDesc('created_at');

        if ($ids) {
            $query->whereIn('id', explode(',', $ids));
        }

        return $query->get();
    }

    private function slideResource(Slide $slide): array
    {
        return [
            'id'                => $slide->id,
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
