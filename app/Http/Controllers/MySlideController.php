<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Models\Slide;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MySlideController extends Controller
{
    public function index(Request $request): Response
    {
        $slides = Slide::with('entity')
            ->where('uploaded_by', $request->user()->id)
            ->whereNull('entity_id')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($s) => $this->slideResource($s));

        $languages = Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']);

        return Inertia::render('MySlides/Index', compact('slides', 'languages'));
    }

    public function edit(Request $request, Slide $slide): Response
    {
        $this->authorizeOwnership($request, $slide);

        $languages = Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']);

        return Inertia::render('MySlides/Edit', [
            'slide' => $this->slideResource($slide),
            'languages' => $languages,
        ]);
    }

    public function update(Request $request, Slide $slide)
    {
        $this->authorizeOwnership($request, $slide);

        $request->validate([
            'title'       => 'required|string|max:255',
            'notes'       => 'nullable|string',
            'language_id' => 'nullable|integer|exists:languages,id',
            'publish_at'  => 'nullable|date',
            'expires_at'  => 'nullable|date|after_or_equal:publish_at',
        ]);

        $slide->update($request->only('title', 'notes', 'language_id', 'publish_at', 'expires_at'));

        return redirect()->route('my-slides.index')->with('success', 'Slide updated.');
    }

    public function archive(Request $request, Slide $slide)
    {
        $this->authorizeOwnership($request, $slide);

        $slide->update(['expires_at' => now()]);

        return back()->with('success', 'Slide archived.');
    }

    private function authorizeOwnership(Request $request, Slide $slide): void
    {
        abort_unless(
            $slide->uploaded_by === $request->user()->id && $slide->entity_id === null,
            403
        );
    }

    private function slideResource(Slide $slide): array
    {
        return [
            'id'                => $slide->id,
            'title'             => $slide->title,
            'notes'             => $slide->notes,
            'language_id'       => $slide->language_id,
            'mime_type'         => $slide->mime_type,
            'file_url'          => $slide->file_url,
            'thumbnail_url'     => $slide->thumbnail_url,
            'publish_at'        => $slide->publish_at?->toIso8601String(),
            'expires_at'        => $slide->expires_at?->toIso8601String(),
            'status'            => $slide->status,
            'original_filename' => $slide->original_filename,
            'file_size'         => $slide->file_size,
            'created_at'        => $slide->created_at->toIso8601String(),
        ];
    }
}
