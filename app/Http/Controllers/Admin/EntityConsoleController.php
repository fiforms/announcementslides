<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\Slide;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class EntityConsoleController extends Controller
{
    public function index(): Response
    {
        $entities = Entity::query()
            ->withCount([
                'slides' => fn ($q) => $q->whereNotNull('entity_id'),
                'users as leaders_count' => fn ($q) => $q->where('user_entities.role', 'admin'),
                'users as members_count',
            ])
            ->orderBy('name')
            ->get()
            ->map(fn ($e) => [
                'id'            => $e->id,
                'name'          => $e->name,
                'entity_type'   => $e->entity_type,
                'city'          => $e->city,
                'state'         => $e->state,
                'deactivated'   => $e->deactivated,
                'slides_count'  => $e->slides_count,
                'leaders_count' => $e->leaders_count,
                'members_count' => $e->members_count,
            ]);

        return Inertia::render('Admin/Entities/Index', compact('entities'));
    }

    /** Full entity record plus everyone with a role on it (loaded on demand by the details lightbox). */
    public function show(Entity $entity): JsonResponse
    {
        $users = $entity->users()
            ->orderByRaw("user_entities.role = 'admin' desc")
            ->orderBy('users.name')
            ->get()
            ->map(fn ($u) => [
                'id'          => $u->id,
                'name'        => $u->name,
                'email'       => $u->email,
                'global_role' => $u->role,
                'entity_role' => $u->pivot->role,
                'granted_by'  => $u->pivot->granted_by,
            ]);

        return response()->json([
            'id'                 => $entity->id,
            'name'               => $entity->name,
            'org_mast_id'        => $entity->org_mast_id,
            'entity_type'        => $entity->entity_type,
            'description'        => $entity->description,
            'address'            => $entity->address,
            'city'               => $entity->city,
            'state'              => $entity->state,
            'zip'                => $entity->zip,
            'country'            => $entity->country,
            'latitude'           => $entity->latitude,
            'longitude'          => $entity->longitude,
            'website'            => $entity->website,
            'is_custom'          => $entity->is_custom,
            'deactivated'        => $entity->deactivated,
            'allow_subscription' => $entity->allow_subscription,
            'created_by'         => $entity->creator?->name,
            'created_at'         => $entity->created_at?->toIso8601String(),
            'slides_count'       => $entity->slides()->count(),
            'users'              => $users,
        ]);
    }

    public function slides(Entity $entity): Response
    {
        $slides = Slide::with(['uploader', 'primaryMedia'])
            ->entityScoped($entity->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($s) => [
                'id'                => $s->id,
                'title'             => $s->title,
                'notes'             => $s->notes,
                'mime_type'         => $s->mime_type,
                'file_url'          => $s->file_url,
                'thumbnail_url'     => $s->thumbnail_url,
                'publish_at'        => $s->publish_at?->toIso8601String(),
                'expires_at'        => $s->expires_at?->toIso8601String(),
                'status'            => $s->status,
                'original_filename' => $s->original_filename,
                'file_size'         => $s->file_size,
                'uploader'          => $s->uploader?->only('id', 'name'),
                'created_at'        => $s->created_at->toIso8601String(),
            ]);

        return Inertia::render('Admin/Entities/Slides', [
            'entity' => ['id' => $entity->id, 'name' => $entity->name],
            'slides' => $slides,
        ]);
    }
}
