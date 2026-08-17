<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\Slide;
use Inertia\Inertia;
use Inertia\Response;

class EntityConsoleController extends Controller
{
    public function index(): Response
    {
        $entities = Entity::where('deactivated', false)
            ->withCount(['slides' => fn ($q) => $q->whereNotNull('entity_id')])
            ->orderBy('name')
            ->get()
            ->map(fn ($e) => [
                'id'          => $e->id,
                'name'        => $e->name,
                'entity_type' => $e->entity_type,
                'slides_count'=> $e->slides_count,
            ]);

        return Inertia::render('Admin/Entities/Index', compact('entities'));
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
