<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Models\Slide;
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

        $query = Slide::current()->language($languageId);

        if ($entityId) {
            $query->where(fn ($q) => $q->whereNull('entity_id')->orWhere('entity_id', $entityId));
        } else {
            $query->visibleToUser($request->user());
        }

        $slides = $query->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($s) => $this->slideResource($s));

        $languages = Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']);

        return Inertia::render('Slides/Index', [
            'slides' => $slides,
            'languages' => $languages,
            'selectedLanguage' => $languageCode,
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

        $query = Slide::archived()->language($languageId);

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

        return Storage::download($slide->disk_path, $slide->original_filename);
    }

    public function downloadZip(Request $request)
    {
        $ids = $request->query('ids');
        $languageCode = $request->query('language');
        $languageId = null;

        if ($languageCode) {
            $language = Language::where('abbreviation', $languageCode)->first();
            $languageId = $language?->id;
        }

        $query = Slide::current()
            ->visibleToUser($request->user())
            ->language($languageId)
            ->orderBy('sort_order');

        if ($ids) {
            $query->whereIn('id', explode(',', $ids));
        }

        $slides = $query->get();

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
            $fullPath = Storage::disk('public')->path($slide->disk_path);
            if (file_exists($fullPath)) {
                $zip->addFile($fullPath, $slide->original_filename);
            }
        }

        $zip->close();

        return response()->download($tmpFile, 'announcement-slides.zip', [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    public function downloadPowerPoint(Request $request)
    {
        $ids = $request->query('ids');
        $languageCode = $request->query('language');
        $languageId = null;

        if ($languageCode) {
            $language = Language::where('abbreviation', $languageCode)->first();
            $languageId = $language?->id;
        }

        $query = Slide::current()
            ->visibleToUser($request->user())
            ->language($languageId)
            ->orderBy('sort_order');

        if ($ids) {
            $query->whereIn('id', explode(',', $ids));
        }

        $slides = $query->get();

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
            $fullPath = Storage::disk('public')->path($slide->disk_path);
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

    private function slideResource(Slide $slide): array
    {
        return [
            'id'                => $slide->id,
            'title'             => $slide->title,
            'notes'             => $slide->notes,
            'mime_type'         => $slide->mime_type,
            'file_url'          => $slide->file_url,
            'thumbnail_url'     => $slide->thumbnail_url,
            'publish_at'        => $slide->publish_at?->toIso8601String(),
            'expires_at'        => $slide->expires_at?->toIso8601String(),
            'original_filename' => $slide->original_filename,
            'validation_issues' => $slide->validation_issues,
            'validation_status' => $slide->validation_status,
        ];
    }
}
