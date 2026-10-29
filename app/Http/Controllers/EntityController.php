<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EntityController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $term = $request->string('q')->trim();

        $entities = Entity::query()
            ->where('deactivated', false)
            ->when($term->isNotEmpty(), fn ($q) => $q->search((string) $term))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'city', 'state', 'entity_type', 'allow_subscription']);

        return response()->json($entities);
    }

    public function subscribe(Request $request, Entity $entity): RedirectResponse
    {
        $user = $request->user();

        abort_unless($entity->allow_subscription, 403, 'This church does not accept subscribers.');

        // Self-service subscriptions are always 'viewer'; admins are assigned via CLI/admin UI.
        $user->entities()->syncWithoutDetaching([
            $entity->id => ['role' => 'viewer', 'granted_by' => null],
        ]);

        return back()->with('status', 'subscribed');
    }

    public function unsubscribe(Request $request, Entity $entity): RedirectResponse
    {
        $request->user()->entities()->detach($entity->id);

        return back()->with('status', 'unsubscribed');
    }
}
