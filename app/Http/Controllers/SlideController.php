<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\GlobalShowTemplate;
use App\Models\Language;
use App\Models\Show;
use App\Models\Slide;
use App\Support\NearbyEntities;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
    public function index(Request $request): Response
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
            $availableShows = $entityShows->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all();
        } else {
            $globalBoard = Show::globalBoard();
            $showId = $request->query('show_id') ? (int) $request->query('show_id') : $globalBoard->id;

            // One-off distributed shows (e.g. a special promo video) don't have
            // a single show row — each entity got its own copy — so pick one
            // representative copy per template purely so an anonymous/global
            // viewer can preview the same content.
            $availableShows = collect([['id' => $globalBoard->id, 'name' => 'Announcements']])
                ->concat(
                    GlobalShowTemplate::has('shows')->get()->map(fn ($t) => [
                        'id' => $t->shows()->first()->id,
                        'name' => $t->name,
                    ])
                )->all();
        }

        // Language filtering only applies in Global View: an entity's shows
        // already encode their own preferred language (see Show::$language_id),
        // so filtering again on top would fight the leader's own curation.
        $slidesQuery = Slide::with(['primaryMedia', 'overlayMedia'])->orderedInShow($showId)->current();
        if (!$entityId) {
            $slidesQuery->language($languageId);
        }
        $slides = $slidesQuery->get()->map(fn ($s) => $this->slideResource($s));

        $languages = Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']);

        return Inertia::render('Slides/Index', [
            'slides' => $slides,
            'languages' => $languages,
            'selectedLanguage' => $languageCode,
            'entityId' => $entityId,
            'showId' => $showId,
            'availableShows' => $availableShows,
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

        $query = Slide::with(['primaryMedia', 'overlayMedia'])->archived()->language($languageId);

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

        $slides = $query->paginate(24)->through(fn ($s) => $this->slideResource($s));

        $languages = Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']);

        return Inertia::render('Slides/Archive', [
            'slides' => $slides,
            'languages' => $languages,
            'search' => $search,
            'selectedLanguage' => $languageCode,
        ]);
    }

    public function download(Slide $slide)
    {
        abort_unless(
            $slide->status === 'published',
            404
        );

        $media = $slide->primaryMedia;
        abort_unless($media, 404);

        return Storage::download($media->disk_path, $media->original_filename);
    }

    public function downloadZip(Request $request)
    {
        $slides = $this->resolveDownloadSlides($request);

        if ($slides->isEmpty()) {
            abort(404);
        }

        $zip     = new ZipArchive();
        $tmpFile = tempnam(sys_get_temp_dir(), 'slides_');
        unlink($tmpFile);

        if ($zip->open($tmpFile, ZipArchive::CREATE) !== true) {
            abort(500, 'Could not create zip archive.');
        }

        foreach ($slides as $slide) {
            $media = $slide->primaryMedia;
            if (! $media) {
                continue;
            }
            $fullPath = Storage::disk('public')->path($media->disk_path);
            if (file_exists($fullPath)) {
                $zip->addFile($fullPath, $media->original_filename);
            }
        }

        $zip->close();

        return response()->download($tmpFile, 'announcement-slides.zip', [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    public function downloadPowerPoint(Request $request)
    {
        $slides = $this->resolveDownloadSlides($request);

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

        foreach ($slides as $slide) {
            $media = $slide->primaryMedia;
            if (! $media) {
                continue;
            }
            $fullPath = Storage::disk('public')->path($media->disk_path);
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
        $oWriterPPTX->save($tmpFile);

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

        if ($showId) {
            return Slide::with('primaryMedia')->orderedInShow((int) $showId)->current()->get();
        }

        $ids = $request->query('ids');
        $languageCode = $request->query('language');
        $languageId = null;

        if ($languageCode) {
            $language = Language::where('abbreviation', $languageCode)->first();
            $languageId = $language?->id;
        }

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
            'publish_at'        => $slide->publish_at?->toIso8601String(),
            'expires_at'        => $slide->expires_at?->toIso8601String(),
            'original_filename' => $slide->original_filename,
            'validation_issues' => $slide->validation_issues,
            'validation_status' => $slide->validation_status,
            'entity_id'         => $slide->entity_id,
        ];
    }
}
