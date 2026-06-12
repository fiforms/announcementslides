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
        // Group by effective status (published → draft → archived), then fall back
        // to the global slide sort order. Archived is a derived state, so the
        // ranking has to happen in PHP rather than a SQL ORDER BY.
        $statusRank = [
            'published' => 0,
            'scheduled' => 1,
            'pending'   => 2,
            'draft'     => 3,
            'rejected'  => 4,
            'archived'  => 5,
        ];

        $slides = Slide::with('entity')
            ->where('uploaded_by', $request->user()->id)
            ->whereNull('entity_id')
            ->get()
            ->sortBy(fn ($s) => [
                $statusRank[$s->display_status] ?? 99,
                $s->sort_order,
                -$s->created_at->getTimestamp(),
            ])
            ->values()
            ->map(fn ($s) => $this->slideResource($s));

        $languages = Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']);

        $canSetStatus = $request->user()->isContributor();

        return Inertia::render('MySlides/Index', compact('slides', 'languages', 'canSetStatus'));
    }

    public function edit(Request $request, Slide $slide): Response
    {
        $this->authorizeOwnership($request, $slide);

        $languages = Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']);

        return Inertia::render('MySlides/Edit', [
            'slide' => $this->slideResource($slide),
            'languages' => $languages,
            'canSetStatus' => $request->user()->isContributor(),
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
            'status'      => 'nullable|in:draft,pending,published',
        ]);

        $fields = $request->only('title', 'notes', 'language_id', 'publish_at', 'expires_at');

        // Only contributors may change the workflow status of their slide.
        if ($request->filled('status') && $request->user()->isContributor()) {
            $fields['status'] = $request->status;
        }

        $slide->update($fields);

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
        // Ownership alone is not enough: a user demoted to "viewer" may still own
        // global slides from when they were a contributor, but must not be able to
        // modify them. Editing/archiving requires current contributor permissions.
        abort_unless(
            $request->user()->isContributor()
                && $slide->uploaded_by === $request->user()->id
                && $slide->entity_id === null,
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
            'display_status'    => $slide->display_status,
            'original_filename' => $slide->original_filename,
            'file_size'         => $slide->file_size,
            'validation_issues' => $slide->validation_issues,
            'validation_status' => $slide->validation_status,
            'created_at'        => $slide->created_at->toIso8601String(),
        ];
    }
}
