<?php

namespace App\Http\Controllers;

use App\Models\Slide;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use ZipArchive;

class SlideController extends Controller
{
    public function index(): Response
    {
        $slides = Slide::current()
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($s) => $this->slideResource($s));

        return Inertia::render('Slides/Index', ['slides' => $slides]);
    }

    public function archive(Request $request): Response
    {
        $query = Slide::archived()->orderByDesc('expires_at');

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%"));
        }

        $slides = $query->paginate(24)->through(fn ($s) => $this->slideResource($s));

        return Inertia::render('Slides/Archive', [
            'slides' => $slides,
            'search' => $search,
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

        $query = Slide::current()->orderBy('sort_order');

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

    private function slideResource(Slide $slide): array
    {
        return [
            'id'               => $slide->id,
            'title'            => $slide->title,
            'notes'            => $slide->notes,
            'mime_type'        => $slide->mime_type,
            'file_url'         => $slide->file_url,
            'thumbnail_url'    => $slide->thumbnail_url,
            'publish_at'       => $slide->publish_at?->toIso8601String(),
            'expires_at'       => $slide->expires_at?->toIso8601String(),
            'original_filename'=> $slide->original_filename,
        ];
    }
}
