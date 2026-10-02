<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesEntityAccess;
use App\Models\Entity;
use App\Models\Language;
use App\Models\PlayLink;
use App\Models\Show;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Entity leaders' management of shareable no-login playback links (see
 * PlayLink / PlayController). Same guard as the other entity-leader screens.
 */
class PlayLinkController extends Controller
{
    use AuthorizesEntityAccess;

    public function index(Request $request): Response|RedirectResponse
    {
        $entityId = $this->authorizedEntityId($request);
        if ($redirect = $this->redirectToEntityUrl($request, 'play-links.index', $entityId)) {
            return $redirect;
        }

        $entity = Entity::findOrFail($entityId);
        $entity->mainShow();

        return Inertia::render('Entity/PlayLinks', [
            'entity' => ['id' => $entity->id, 'name' => $entity->name],
            'links' => PlayLink::active()
                ->where('entity_id', $entityId)
                ->orderBy('title')
                ->get()
                ->map(fn (PlayLink $link) => [
                    'id' => $link->id,
                    'title' => $link->title,
                    'show_id' => $link->show_id,
                    'language_id' => $link->language_id,
                    'delay_seconds' => $link->delay_seconds,
                    'url' => $link->url(),
                    'last_used_at' => $link->last_used_at?->toIso8601String(),
                    'created_at' => $link->created_at->toIso8601String(),
                ]),
            'shows' => Show::where('entity_id', $entityId)->orderByDesc('is_main')->orderBy('name')->get(['id', 'name', 'is_main']),
            'languages' => Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']),
            'defaultDelaySeconds' => $request->user()->slideDelaySeconds(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $entityId = $this->authorizedEntityId($request);

        PlayLink::create([
            ...$this->validated($request, $entityId),
            'entity_id' => $entityId,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Link created.');
    }

    public function update(Request $request, PlayLink $playLink): RedirectResponse
    {
        $this->authorizeLink($request, $playLink);

        $playLink->update($this->validated($request, $playLink->entity_id));

        return back()->with('success', 'Link updated.');
    }

    /** Revoking keeps the row (for history) but the URL stops working at once. */
    public function destroy(Request $request, PlayLink $playLink): RedirectResponse
    {
        $this->authorizeLink($request, $playLink);

        $playLink->update(['revoked_at' => now()]);

        return back()->with('success', 'Link revoked.');
    }

    private function authorizeLink(Request $request, PlayLink $link): void
    {
        $user = $request->user();
        abort_unless($link->revoked_at === null && ($user->isAdmin() || $user->isEntityAdmin($link->entity_id)), 403);
    }

    private function validated(Request $request, int $entityId): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'show_id' => ['nullable', Rule::exists('shows', 'id')->where('entity_id', $entityId)->whereNull('deleted_at')],
            'language_id' => ['nullable', 'exists:languages,id'],
            'delay_seconds' => ['required', 'integer', 'min:1', 'max:600'],
        ]);
    }
}
