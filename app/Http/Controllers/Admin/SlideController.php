<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateThumbnail;
use App\Models\Slide;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SlideController extends Controller
{
    public function index(): Response
    {
        $current  = Slide::current()->orderBy('sort_order')->orderByDesc('created_at')->get()->map(fn ($s) => $this->slideResource($s));
        $pending  = Slide::pendingReview()->orderByDesc('created_at')->get()->map(fn ($s) => $this->slideResource($s));
        $upcoming = Slide::upcoming()->orderBy('publish_at')->get()->map(fn ($s) => $this->slideResource($s));
        $archived = Slide::archived()->orderByDesc('expires_at')->limit(20)->get()->map(fn ($s) => $this->slideResource($s));
        $drafts   = Slide::where('status', 'draft')->orderByDesc('created_at')->get()->map(fn ($s) => $this->slideResource($s));

        return Inertia::render('Admin/Slides/Index', compact('current', 'pending', 'upcoming', 'archived', 'drafts'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'files'        => 'required|array|min:1',
            'files.*'      => 'required|file|mimes:jpeg,jpg,png,webp,gif,mp4,mov,webm|max:204800',
            'title'        => 'required|string|max:255',
            'notes'        => 'nullable|string',
            'publish_at'   => 'nullable|date',
            'expires_at'   => 'nullable|date|after_or_equal:publish_at',
            'status'       => 'in:draft,published',
        ]);

        $slides = [];

        foreach ($request->file('files') as $file) {
            $uuid       = Str::uuid();
            $ext        = $file->getClientOriginalExtension();
            $filename   = "{$uuid}.{$ext}";
            $diskPath   = "slides/{$filename}";

            $file->storeAs('slides', $filename);

            $slide = Slide::create([
                'title'             => $request->title,
                'notes'             => $request->notes,
                'filename'          => $filename,
                'original_filename' => $file->getClientOriginalName(),
                'disk_path'         => $diskPath,
                'file_size'         => $file->getSize(),
                'mime_type'         => $file->getMimeType(),
                'publish_at'        => $request->publish_at,
                'expires_at'        => $request->expires_at,
                'status'            => $request->status ?? 'published',
                'uploaded_by'       => $request->user()->id,
            ]);

            GenerateThumbnail::dispatch($slide);
            $slides[] = $slide;
        }

        return redirect()->route('admin.slides.index')
            ->with('success', count($slides) . ' slide(s) uploaded successfully.');
    }

    public function edit(Slide $slide): Response
    {
        return Inertia::render('Admin/Slides/Edit', ['slide' => $this->slideResource($slide)]);
    }

    public function update(Request $request, Slide $slide)
    {
        $request->validate([
            'title'      => 'required|string|max:255',
            'notes'      => 'nullable|string',
            'publish_at' => 'nullable|date',
            'expires_at' => 'nullable|date',
            'status'     => 'required|in:draft,pending,published,rejected',
            'sort_order' => 'integer|min:0',
        ]);

        $slide->update($request->only('title', 'notes', 'publish_at', 'expires_at', 'status', 'sort_order'));

        return redirect()->route('admin.slides.index')
            ->with('success', 'Slide updated.');
    }

    public function approve(Slide $slide)
    {
        $slide->update([
            'status'      => 'published',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Slide approved and published.');
    }

    public function reject(Slide $slide)
    {
        $slide->update([
            'status'      => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Slide rejected.');
    }

    public function reorder(Request $request)
    {
        $request->validate(['order' => 'required|array', 'order.*' => 'integer']);

        foreach ($request->order as $position => $id) {
            Slide::where('id', $id)->update(['sort_order' => $position]);
        }

        return response()->json(['ok' => true]);
    }

    public function destroy(Slide $slide)
    {
        $slide->delete();

        return back()->with('success', 'Slide removed.');
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
            'status'            => $slide->status,
            'sort_order'        => $slide->sort_order,
            'original_filename' => $slide->original_filename,
            'file_size'         => $slide->file_size,
            'uploader'          => $slide->uploader?->only('id', 'name'),
            'created_at'        => $slide->created_at->toIso8601String(),
        ];
    }
}
