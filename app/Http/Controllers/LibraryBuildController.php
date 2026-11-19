<?php

namespace App\Http\Controllers;

use App\Models\LibraryItem;
use App\Models\Slide;
use App\Services\LibrarySlides;
use App\Services\SlidePlacement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Build a slide" from the admin-managed library: a shared background plus an
 * optional overlay template. The slide's media row points at the library's
 * single stored background file (nothing is copied); the template's overlay
 * SVG, which carries the editor source and any widget placements, is copied
 * into the slide as its own overlay, which the leader then edits like any
 * other. Later changes to the template don't touch existing slides.
 */
class LibraryBuildController extends Controller
{
    public function catalog(Request $request): JsonResponse
    {
        $this->authorizeBuilder($request);

        $card = fn (LibraryItem $i) => [
            'id' => $i->id, 'title' => $i->title, 'preview_url' => $i->preview_url,
            'thumbnail_url' => $i->thumbnail_url, 'is_video' => $i->isVideo(),
        ];

        return response()->json([
            'backgrounds' => LibraryItem::backgrounds()->ready()->orderByDesc('created_at')->get()->map($card),
            'templates'   => LibraryItem::templates()->ready()->orderBy('title')->get()->map($card),
        ]);
    }

    public function build(Request $request, SlidePlacement $placement, LibrarySlides $library): JsonResponse
    {
        $data = $request->validate([
            'title'              => 'required|string|max:255',
            'entity_id'          => 'nullable|integer|exists:entities,id',
            'background_id'      => 'nullable|integer|exists:library_items,id',
            'template_id'        => 'nullable|integer|exists:library_items,id',
            'language_id'        => 'nullable|integer|exists:languages,id',
            'add_to_show'        => 'nullable|in:main,separate,none',
            'show_id'            => 'nullable|integer|exists:shows,id',
            'new_show_name'      => 'nullable|string|max:255',
            'global_template_id' => 'nullable|integer|exists:global_show_templates,id',
        ]);

        $user = $request->user();
        $entityId = isset($data['entity_id']) ? (int) $data['entity_id'] : null;
        if ($entityId !== null) {
            abort_unless($user->isAdmin() || $user->isEntityAdmin($entityId), 403);
        } else {
            abort_unless($user->isAdmin(), 403); // global slides are for site admins
        }

        $background = isset($data['background_id'])
            ? LibraryItem::backgrounds()->ready()->find($data['background_id']) : null;
        $template = isset($data['template_id'])
            ? LibraryItem::templates()->ready()->find($data['template_id']) : null;

        if (!$background && !$template) {
            return response()->json(['message' => 'Pick a background or a template.'], 422);
        }
        if ((isset($data['background_id']) && !$background) || (isset($data['template_id']) && !$template)) {
            return response()->json(['message' => 'That library item is no longer available.'], 422);
        }

        $slide = Slide::create([
            'title'       => $data['title'],
            'status'      => 'published',
            'uploaded_by' => $user->id,
            'entity_id'   => $entityId,
            'language_id' => $data['language_id'] ?? null,
        ]);

        $background && $library->attachBackground($slide, $background);
        $template && $library->attachTemplate($slide, $template);

        $placement->route($request, [$slide], $entityId);

        return response()->json(['slide_id' => $slide->id, 'has_overlay' => (bool) $template, 'entity_id' => $entityId]);
    }

    private function authorizeBuilder(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->adminEntities()->exists(), 403);
    }
}
