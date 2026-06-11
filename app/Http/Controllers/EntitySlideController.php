<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\Language;
use App\Models\Slide;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EntitySlideController extends Controller
{
    public function index(Request $request, Entity $entity): Response
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isEntityAdmin($entity->id), 403);

        $slides = Slide::with('uploader')
            ->entityScoped($entity->id)
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($s) => $this->slideResource($s));

        $languages = Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']);

        return Inertia::render('Entity/Slides', [
            'entity' => ['id' => $entity->id, 'name' => $entity->name],
            'slides' => $slides,
            'languages' => $languages,
        ]);
    }

    public function edit(Request $request, Entity $entity, Slide $slide): Response
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isEntityAdmin($entity->id), 403);
        abort_unless($user->isAdmin() || $slide->uploaded_by === $user->id, 403);
        abort_unless($slide->entity_id === $entity->id, 404);

        return Inertia::render('Entity/Edit', [
            'entity' => ['id' => $entity->id, 'name' => $entity->name],
            'slide'  => $this->slideResource($slide),
        ]);
    }

    public function update(Request $request, Entity $entity, Slide $slide)
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isEntityAdmin($entity->id), 403);
        abort_unless($user->isAdmin() || $slide->uploaded_by === $user->id, 403);
        abort_unless($slide->entity_id === $entity->id, 404);

        $request->validate([
            'title'      => 'required|string|max:255',
            'notes'      => 'nullable|string',
            'publish_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after_or_equal:publish_at',
        ]);

        $slide->update($request->only('title', 'notes', 'publish_at', 'expires_at'));

        return redirect()->route('entity.slides.index', $entity)->with('success', 'Slide updated.');
    }

    public function archive(Request $request, Entity $entity, Slide $slide)
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isEntityAdmin($entity->id), 403);
        abort_unless($user->isAdmin() || $slide->uploaded_by === $user->id, 403);
        abort_unless($slide->entity_id === $entity->id, 404);

        $slide->update(['expires_at' => now()]);

        return back()->with('success', 'Slide archived.');
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
