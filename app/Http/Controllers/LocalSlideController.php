<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\Language;
use App\Models\Slide;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LocalSlideController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $entityId = (int) $request->query('entity_id');

        abort_unless(
            $entityId && in_array($entityId, $user->memberEntityIds()),
            403
        );

        $entity = Entity::findOrFail($entityId);
        $isAdmin = $user->isAdmin() || $user->isEntityAdmin($entityId);

        $slides = Slide::with('uploader')
            ->entityScoped($entityId)
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($s) => $this->slideResource($s));

        $languages = Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']);

        return Inertia::render('LocalSlides/Index', [
            'entity'  => ['id' => $entity->id, 'name' => $entity->name],
            'slides'  => $slides,
            'languages' => $languages,
            'isAdmin' => $isAdmin,
        ]);
    }

    public function edit(Request $request, Slide $slide): Response
    {
        $user = $request->user();
        $entityId = (int) $request->query('entity_id');

        abort_unless(
            $entityId && in_array($entityId, $user->memberEntityIds()),
            403
        );
        abort_unless($user->isAdmin() || $user->isEntityAdmin($entityId), 403);
        abort_unless($user->isAdmin() || $slide->uploaded_by === $user->id, 403);
        abort_unless($slide->entity_id === $entityId, 404);

        $entity = Entity::findOrFail($entityId);
        $languages = Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']);

        return Inertia::render('LocalSlides/Edit', [
            'entity'  => ['id' => $entity->id, 'name' => $entity->name],
            'slide'   => $this->slideResource($slide),
            'languages' => $languages,
        ]);
    }

    public function update(Request $request, Slide $slide)
    {
        $user = $request->user();
        $entityId = (int) $request->query('entity_id');

        abort_unless(
            $entityId && in_array($entityId, $user->memberEntityIds()),
            403
        );
        abort_unless($user->isAdmin() || $user->isEntityAdmin($entityId), 403);
        abort_unless($user->isAdmin() || $slide->uploaded_by === $user->id, 403);
        abort_unless($slide->entity_id === $entityId, 404);

        $request->validate([
            'title'      => 'required|string|max:255',
            'notes'      => 'nullable|string',
            'publish_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after_or_equal:publish_at',
        ]);

        $slide->update($request->only('title', 'notes', 'publish_at', 'expires_at'));

        return redirect()->route('local-slides.index', ['entity_id' => $entityId])->with('success', 'Slide updated.');
    }

    public function archive(Request $request, Slide $slide)
    {
        $user = $request->user();
        $entityId = (int) $request->query('entity_id');

        abort_unless(
            $entityId && in_array($entityId, $user->memberEntityIds()),
            403
        );
        abort_unless($user->isAdmin() || $user->isEntityAdmin($entityId), 403);
        abort_unless($user->isAdmin() || $slide->uploaded_by === $user->id, 403);
        abort_unless($slide->entity_id === $entityId, 404);

        $slide->update(['expires_at' => now()]);

        return back()->with('success', 'Slide archived.');
    }

    public function unarchive(Request $request, Slide $slide)
    {
        $user = $request->user();
        $entityId = (int) $request->query('entity_id');

        abort_unless(
            $entityId && in_array($entityId, $user->memberEntityIds()),
            403
        );
        abort_unless($user->isAdmin() || $user->isEntityAdmin($entityId), 403);
        abort_unless($user->isAdmin() || $slide->uploaded_by === $user->id, 403);
        abort_unless($slide->entity_id === $entityId, 404);

        $slide->update(['expires_at' => null]);

        return back()->with('success', 'Slide restored.');
    }

    public function reorder(Request $request)
    {
        $user = $request->user();
        $entityId = (int) $request->query('entity_id');

        abort_unless(
            $entityId && in_array($entityId, $user->memberEntityIds()),
            403
        );
        abort_unless($user->isAdmin() || $user->isEntityAdmin($entityId), 403);

        $request->validate([
            'slides' => 'required|array',
            'slides.*' => 'integer|exists:slides,id',
        ]);

        $slides = Slide::entityScoped($entityId)->whereIn('id', $request->input('slides'))->get();

        foreach ($request->input('slides') as $index => $slideId) {
            $slide = $slides->find($slideId);
            if ($slide) {
                $slide->update(['sort_order' => $index]);
            }
        }

        return response()->json(['success' => true]);
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
            'validation_issues' => $slide->validation_issues,
            'validation_status' => $slide->validation_status,
            'uploader'          => $slide->uploader?->only('id', 'name'),
            'created_at'        => $slide->created_at->toIso8601String(),
        ];
    }
}
