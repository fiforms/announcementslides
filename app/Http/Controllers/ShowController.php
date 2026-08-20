<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesEntityAccess;
use App\Http\Controllers\Concerns\ManagesSlideMedia;
use App\Models\Entity;
use App\Models\Language;
use App\Models\Show;
use App\Models\Slide;
use App\Support\NearbyEntities;
use App\Support\SortZones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ShowController extends Controller
{
    use AuthorizesEntityAccess;
    use ManagesSlideMedia;

    public function index(Request $request): Response|RedirectResponse
    {
        $entityId = $this->authorizedEntityId($request, requireAdmin: false);
        if ($redirect = $this->redirectToEntityUrl($request, 'shows.index', $entityId)) {
            return $redirect;
        }

        $entity = Entity::findOrFail($entityId);
        $entity->mainShow();

        $shows = Show::where('entity_id', $entityId)->orderByDesc('is_main')->orderBy('name')->get();
        $mainShow = $shows->firstWhere('is_main', true);
        $selectedShowId = (int) ($request->query('show_id') ?: $mainShow->id);
        $selectedShow = $shows->firstWhere('id', $selectedShowId) ?? $mainShow;

        $showSlides = Slide::with(['primaryMedia', 'overlayMedia', 'media', 'uploader'])
            ->orderedInShow($selectedShow->id)
            ->get();

        $unusedSlides = $this->unusedSlidesQuery($entity, $selectedShow, $shows->pluck('id'))->get();

        $isAdmin = $request->user()->isAdmin() || $request->user()->isEntityAdmin($entityId);
        $languages = Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']);

        return Inertia::render('Shows/Manage', [
            'entity' => ['id' => $entity->id, 'name' => $entity->name],
            'shows' => $shows->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'is_main' => $s->is_main,
                'language_id' => $s->language_id,
                'auto_fill_global' => $s->auto_fill_global, 'auto_fill_nearby' => $s->auto_fill_nearby,
            ]),
            'selectedShowId' => $selectedShow->id,
            'showSlides' => $showSlides->map(fn ($s) => $this->slideResource($s)),
            'unusedSlides' => $unusedSlides->map(fn ($s) => $this->slideResource($s)),
            'isAdmin' => $isAdmin,
            'languages' => $languages,
            'mediaTypes' => $this->mediaTypesForFrontend(),
        ]);
    }

    public function store(Request $request)
    {
        $entityId = $this->authorizedEntityId($request);

        $request->validate([
            'name' => 'required|string|max:255',
            'language_id' => 'nullable|integer|exists:languages,id',
            'auto_fill_global' => 'boolean',
            'auto_fill_nearby' => 'boolean',
        ]);

        $show = Show::create([
            'entity_id' => $entityId,
            'name' => $request->name,
            'is_main' => false,
            'language_id' => $request->language_id,
            'auto_fill_global' => $request->boolean('auto_fill_global'),
            'auto_fill_nearby' => $request->boolean('auto_fill_nearby'),
            'created_by' => $request->user()->id,
        ]);

        if ($show->auto_fill_global || $show->auto_fill_nearby) {
            $show->syncAutoFillFromCandidates();
        }

        return redirect()->route('shows.index', ['entity_id' => $entityId, 'show_id' => $show->id])
            ->with('success', 'Show created.');
    }

    public function update(Request $request, Show $show)
    {
        $entityId = $this->authorizedEntityId($request);
        abort_unless($show->entity_id === $entityId, 404);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'language_id' => 'sometimes|nullable|integer|exists:languages,id',
            'auto_fill_global' => 'sometimes|boolean',
            'auto_fill_nearby' => 'sometimes|boolean',
        ]);

        // Each field is a true partial patch: only touch it if the request
        // actually included the key, so e.g. changing just the language
        // doesn't silently reset the auto-fill flags (or vice versa).
        $newLanguageId = $request->has('language_id')
            ? ($request->filled('language_id') ? (int) $request->input('language_id') : null)
            : $show->language_id;

        // Main's whole purpose is auto-receiving global content by default —
        // it can be scoped to a language, but "add global slides" can't be
        // turned off for it. "Add nearby slides" stays freely toggleable.
        $newAutoFillGlobal = $show->is_main
            ? true
            : ($request->has('auto_fill_global') ? $request->boolean('auto_fill_global') : $show->auto_fill_global);
        $newAutoFillNearby = $request->has('auto_fill_nearby')
            ? $request->boolean('auto_fill_nearby')
            : $show->auto_fill_nearby;

        $needsResync = ($newAutoFillGlobal && ($newAutoFillGlobal !== $show->auto_fill_global || $newLanguageId !== $show->language_id))
            || ($newAutoFillNearby && ($newAutoFillNearby !== $show->auto_fill_nearby || $newLanguageId !== $show->language_id));

        $attrs = [
            'language_id' => $newLanguageId,
            'auto_fill_global' => $newAutoFillGlobal,
            'auto_fill_nearby' => $newAutoFillNearby,
        ];
        if ($request->filled('name')) {
            $attrs['name'] = $request->input('name');
        }
        $show->update($attrs);

        if ($needsResync) {
            $show->syncAutoFillFromCandidates();
        }

        return back()->with('success', 'Show updated.');
    }

    public function destroy(Request $request, Show $show)
    {
        $entityId = $this->authorizedEntityId($request);
        abort_unless($show->entity_id === $entityId, 404);
        abort_if($show->is_main, 422, "The main show can't be deleted.");

        $show->delete();

        return redirect()->route('shows.index', ['entity_id' => $entityId])->with('success', 'Show deleted.');
    }

    public function attach(Request $request, Show $show)
    {
        $entityId = $this->authorizedEntityId($request);
        abort_unless($show->entity_id === $entityId, 404);

        $request->validate([
            'slide_id' => 'required|integer|exists:slides,id',
            'zone' => ['nullable', Rule::in(SortZones::leaderZones())],
        ]);

        $entity = Entity::findOrFail($entityId);
        $radius = (float) config('slides.nearby_radius_miles');
        $nearbyIds = NearbyEntities::within($entity, $radius);

        $visible = Slide::where('id', $request->slide_id)
            ->where(function ($q) use ($entityId, $nearbyIds) {
                $q->whereNull('entity_id')->orWhere('entity_id', $entityId);
                if (!empty($nearbyIds)) {
                    $q->orWhere(fn ($n) => $n->whereIn('entity_id', $nearbyIds)->shareNearby());
                }
            })
            ->exists();

        abort_unless($visible, 404);

        $zone = $request->input('zone', SortZones::LEADER_LATE);
        $show->slides()->syncWithoutDetaching([
            $request->slide_id => ['sort_order' => static::nextSortOrderInZone($show, $zone), 'auto_added' => false],
        ]);

        return back()->with('success', 'Slide added to show.');
    }

    /**
     * The sort_order for appending one more slide to the end of $zone within
     * $show — the zone's own start if it's currently empty, otherwise one
     * past its current max (clamped to the zone's end, which in practice is
     * never reached since a zone holds RANGE_SIZE possible positions).
     */
    public static function nextSortOrderInZone(Show $show, string $zone): int
    {
        [$start, $end] = SortZones::bounds($zone);
        $max = $show->slides()->wherePivotBetween('sort_order', [$start, $end])->max('show_slides.sort_order');

        return $max === null ? $start : min((int) $max + 1, $end);
    }

    public function detach(Request $request, Show $show, Slide $slide)
    {
        $entityId = $this->authorizedEntityId($request);
        abort_unless($show->entity_id === $entityId, 404);

        $show->slides()->detach($slide->id);

        return back()->with('success', 'Slide removed from show.');
    }

    /**
     * Bulk-detach every slide in this show that's currently expired — the
     * one-click cleanup for the show editor's collapsible "expired" pane.
     * Expiry never detaches a slide on its own (it's a query-time filter
     * everywhere else), so this is the explicit action that actually removes
     * the pivot rows once someone decides they're done sitting there.
     */
    public function detachExpired(Request $request, Show $show)
    {
        $entityId = $this->authorizedEntityId($request);
        abort_unless($show->entity_id === $entityId, 404);

        $expiredIds = $show->slides()->archived()->pluck('slides.id');
        $show->slides()->detach($expiredIds);

        return back()->with('success', 'Expired slides removed from show.');
    }

    public function reorder(Request $request, Show $show)
    {
        $entityId = $this->authorizedEntityId($request);
        abort_unless($show->entity_id === $entityId, 404);

        $request->validate([
            'zones' => 'required|array',
            'zones.*' => 'array',
            'zones.*.*' => 'integer|exists:slides,id',
        ]);

        static::persistLeaderOrder($show, $request->input('zones'));

        return response()->json(['success' => true]);
    }

    /**
     * Given ordered slide-id arrays keyed by leader zone (leader_early,
     * leader_mid, leader_late), rewrite each listed slide's pivot sort_order
     * to its position within that zone and mark it manually placed
     * (auto_added = false) — including a slide that was previously sitting
     * in the global/nearby zone, since a leader dragging it into a leader
     * zone *is* the manual override (see SortZones docblock). A slide not
     * present in any of the three arrays is left untouched.
     */
    public static function persistLeaderOrder(Show $show, array $zones): void
    {
        $sync = [];
        foreach (SortZones::leaderZones() as $zone) {
            [$start] = SortZones::bounds($zone);
            foreach (($zones[$zone] ?? []) as $index => $slideId) {
                $sync[$slideId] = ['sort_order' => $start + $index, 'auto_added' => false];
            }
        }

        $show->slides()->syncWithoutDetaching($sync);
    }

    /**
     * Slides visible to this entity (its own, nearby-shared, or global) that
     * aren't currently linked into $selectedShow — the "This Show" scope in
     * Shows/Manage.vue's radio toggle. Each result also carries whether it's
     * linked into some *other* show of this entity, so the client can narrow
     * that down further to "All Shows" (linked into nothing at all) purely
     * client-side, the same way it already does its temporary language
     * filter, without a second round trip.
     */
    private function unusedSlidesQuery(Entity $entity, Show $selectedShow, Collection $entityShowIds)
    {
        $radius = (float) config('slides.nearby_radius_miles');
        $nearbyIds = NearbyEntities::within($entity, $radius);
        $otherShowIds = $entityShowIds->reject(fn ($id) => $id === $selectedShow->id)->values();

        return Slide::with(['primaryMedia', 'overlayMedia', 'media', 'uploader'])
            ->current()
            ->where(function ($q) use ($entity, $nearbyIds) {
                $q->whereNull('entity_id')->orWhere('entity_id', $entity->id);
                if (!empty($nearbyIds)) {
                    $q->orWhere(fn ($n) => $n->whereIn('entity_id', $nearbyIds)->shareNearby());
                }
            })
            ->whereDoesntHave('shows', fn ($q) => $q->where('shows.id', $selectedShow->id))
            ->withExists(['shows as linked_elsewhere' => fn ($q) => $q->whereIn('shows.id', $otherShowIds)]);
    }

    private function slideResource(Slide $slide): array
    {
        return [
            'id' => $slide->id,
            'title' => $slide->title,
            'notes' => $slide->notes,
            'text_description' => $slide->text_description,
            'link' => $slide->link,
            'video_playback_mode' => $slide->video_playback_mode,
            'entity_id' => $slide->entity_id,
            'language_id' => $slide->language_id,
            'mime_type' => $slide->mime_type,
            'file_url' => $slide->file_url,
            'thumbnail_url' => $slide->thumbnail_url,
            'overlay_url' => $slide->overlay_url,
            'overlay_mime_type' => $slide->overlay_mime_type,
            'status' => $slide->status,
            'share_nearby' => $slide->share_nearby,
            'publish_at' => $slide->publish_at?->toIso8601String(),
            'expires_at' => $slide->expires_at?->toIso8601String(),
            'uploader' => $slide->uploader?->only('id', 'name'),
            'validation_issues' => $slide->validation_issues,
            'media' => $this->mediaResource($slide),
            'zone' => isset($slide->show_sort_order) ? SortZones::zoneFor((int) $slide->show_sort_order) : null,
            'linked_elsewhere' => (bool) ($slide->linked_elsewhere ?? false),
        ];
    }
}
