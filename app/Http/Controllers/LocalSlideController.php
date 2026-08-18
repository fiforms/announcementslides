<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesEntityAccess;
use App\Http\Controllers\Concerns\ManagesSlideMedia;
use App\Models\Entity;
use App\Models\Language;
use App\Models\Show;
use App\Models\Slide;
use App\Models\SlideMedia;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LocalSlideController extends Controller
{
    use ManagesSlideMedia;
    use AuthorizesEntityAccess;

    public function index(Request $request): Response
    {
        $entityId = $this->authorizedEntityId($request, requireAdmin: false);
        $user = $request->user();
        $entity = Entity::findOrFail($entityId);
        $isAdmin = $user->isAdmin() || $user->isEntityAdmin($entityId);

        $slides = Slide::with(['uploader', 'primaryMedia'])
            ->entityScoped($entityId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($s) => $this->slideResource($s));

        $languages = Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']);
        $shows = Show::where('entity_id', $entityId)->where('is_main', false)->get(['id', 'name']);

        return Inertia::render('LocalSlides/Index', [
            'entity'  => ['id' => $entity->id, 'name' => $entity->name],
            'slides'  => $slides,
            'languages' => $languages,
            'isAdmin' => $isAdmin,
            'shows' => $shows,
        ]);
    }

    public function edit(Request $request, Slide $slide): Response
    {
        $entityId = $this->authorizedEntityId($request);
        $user = $request->user();
        abort_unless($user->isAdmin() || $slide->uploaded_by === $user->id, 403);
        abort_unless($slide->entity_id === $entityId, 404);

        $entity = Entity::findOrFail($entityId);
        $slide->load('media');
        $languages = Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']);

        return Inertia::render('LocalSlides/Edit', [
            'entity'  => ['id' => $entity->id, 'name' => $entity->name],
            'slide'   => $this->slideResource($slide, withMedia: true),
            'languages' => $languages,
            'mediaTypes' => $this->mediaTypesForFrontend(),
        ]);
    }

    public function update(Request $request, Slide $slide)
    {
        $entityId = $this->authorizedEntityId($request);
        $user = $request->user();
        abort_unless($user->isAdmin() || $slide->uploaded_by === $user->id, 403);
        abort_unless($slide->entity_id === $entityId, 404);

        $request->validate([
            'title'               => 'required|string|max:255',
            'notes'               => 'nullable|string',
            'text_description'    => 'nullable|string',
            'link'                => 'nullable|url|max:2048',
            'video_playback_mode' => 'nullable|in:play_through,hold_last_frame,loop',
            'language_id'         => 'nullable|integer|exists:languages,id',
            'publish_at'          => 'nullable|date',
            'expires_at'          => 'nullable|date|after_or_equal:publish_at',
        ]);

        $newLanguageId = $request->filled('language_id') ? (int) $request->input('language_id') : null;
        $languageChanged = $slide->language_id !== $newLanguageId;

        $slide->update($request->only('title', 'notes', 'text_description', 'link', 'video_playback_mode', 'language_id', 'publish_at', 'expires_at'));

        if ($languageChanged && $slide->share_nearby) {
            Show::syncAutoFillForSlide($slide);
        }

        return redirect()->route('local-slides.index', ['entity_id' => $entityId])->with('success', 'Slide updated.');
    }

    public function storeMedia(Request $request, Slide $slide)
    {
        $this->authorizeSlideAction($request, $slide);
        $this->storeMediaForSlide($request, $slide);

        return back()->with('success', 'Media added.');
    }

    public function destroyMedia(Request $request, Slide $slide, SlideMedia $media)
    {
        $this->authorizeSlideAction($request, $slide);
        $this->destroyMediaForSlide($slide, $media);

        return back()->with('success', 'Media removed.');
    }

    public function archive(Request $request, Slide $slide)
    {
        $entityId = $this->authorizeSlideAction($request, $slide);

        $slide->update(['expires_at' => now()]);

        return back()->with('success', 'Slide archived.');
    }

    public function unarchive(Request $request, Slide $slide)
    {
        $this->authorizeSlideAction($request, $slide);

        $slide->update(['expires_at' => null]);

        return back()->with('success', 'Slide restored.');
    }

    public function shareNearby(Request $request, Slide $slide)
    {
        $this->authorizeSlideAction($request, $slide);

        // Shared slides appear on other congregations' dashboards, so they must
        // clear the same quality bar enforced at upload time.
        if ($slide->validation_status !== 'ok') {
            return back()->with('error',
                'This slide can\'t be shared with nearby churches because it doesn\'t meet the quality requirements: '
                . implode('; ', $slide->validation_issues ?? []));
        }

        $slide->update(['share_nearby' => true]);
        Show::syncAutoFillForSlide($slide);

        return back()->with('success', 'Slide is now shared with nearby churches.');
    }

    public function unshareNearby(Request $request, Slide $slide)
    {
        $this->authorizeSlideAction($request, $slide);

        $slide->update(['share_nearby' => false]);
        Show::removeAutoAddedLinksElsewhere($slide, exceptEntityId: $slide->entity_id);

        return back()->with('success', 'Slide is no longer shared with nearby churches.');
    }

    /**
     * Shared permission gate for entity-admin slide actions: the user must be
     * an admin of the entity (or site admin) and own the slide (unless site
     * admin), and the slide must belong to that entity.
     */
    private function authorizeSlideAction(Request $request, Slide $slide): int
    {
        $entityId = $this->authorizedEntityId($request);
        $user = $request->user();

        abort_unless($user->isAdmin() || $slide->uploaded_by === $user->id, 403);
        abort_unless($slide->entity_id === $entityId, 404);

        return $entityId;
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
            'language_id'       => $slide->language_id,
            'mime_type'         => $slide->mime_type,
            'file_url'          => $slide->file_url,
            'thumbnail_url'     => $slide->thumbnail_url,
            'publish_at'        => $slide->publish_at?->toIso8601String(),
            'expires_at'        => $slide->expires_at?->toIso8601String(),
            'status'            => $slide->status,
            'share_nearby'      => $slide->share_nearby,
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
