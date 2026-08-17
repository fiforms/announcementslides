<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesSlideMedia;
use App\Models\Entity;
use App\Models\Language;
use App\Models\Slide;
use App\Models\SlideMedia;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EntitySlideController extends Controller
{
    use ManagesSlideMedia;

    public function index(Request $request, Entity $entity): Response
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isEntityAdmin($entity->id), 403);

        $slides = Slide::with(['uploader', 'primaryMedia'])
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

        $slide->load('media');

        return Inertia::render('Entity/Edit', [
            'entity' => ['id' => $entity->id, 'name' => $entity->name],
            'slide'  => $this->slideResource($slide, withMedia: true),
            'mediaTypes' => $this->mediaTypesForFrontend(),
        ]);
    }

    public function update(Request $request, Entity $entity, Slide $slide)
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isEntityAdmin($entity->id), 403);
        abort_unless($user->isAdmin() || $slide->uploaded_by === $user->id, 403);
        abort_unless($slide->entity_id === $entity->id, 404);

        $request->validate([
            'title'               => 'required|string|max:255',
            'notes'               => 'nullable|string',
            'text_description'    => 'nullable|string',
            'link'                => 'nullable|url|max:2048',
            'video_playback_mode' => 'nullable|in:play_through,hold_last_frame,loop',
            'publish_at'          => 'nullable|date',
            'expires_at'          => 'nullable|date|after_or_equal:publish_at',
        ]);

        $slide->update($request->only('title', 'notes', 'text_description', 'link', 'video_playback_mode', 'publish_at', 'expires_at'));

        return redirect()->route('entity.slides.index', $entity)->with('success', 'Slide updated.');
    }

    public function storeMedia(Request $request, Entity $entity, Slide $slide)
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isEntityAdmin($entity->id), 403);
        abort_unless($user->isAdmin() || $slide->uploaded_by === $user->id, 403);
        abort_unless($slide->entity_id === $entity->id, 404);

        $this->storeMediaForSlide($request, $slide);

        return back()->with('success', 'Media added.');
    }

    public function destroyMedia(Request $request, Entity $entity, Slide $slide, SlideMedia $media)
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isEntityAdmin($entity->id), 403);
        abort_unless($user->isAdmin() || $slide->uploaded_by === $user->id, 403);
        abort_unless($slide->entity_id === $entity->id, 404);

        $this->destroyMediaForSlide($slide, $media);

        return back()->with('success', 'Media removed.');
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

    private function slideResource(Slide $slide, bool $withMedia = false): array
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
            'publish_at'        => $slide->publish_at?->toIso8601String(),
            'expires_at'        => $slide->expires_at?->toIso8601String(),
            'status'            => $slide->status,
            'sort_order'        => $slide->sort_order,
            'original_filename' => $slide->original_filename,
            'file_size'         => $slide->file_size,
            'validation_issues' => $slide->validation_issues,
            'validation_status' => $slide->validation_status,
            'uploader'          => $slide->uploader?->only('id', 'name'),
            'created_at'        => $slide->created_at->toIso8601String(),
            'media'             => $withMedia ? $this->mediaResource($slide) : null,
        ];
    }
}
