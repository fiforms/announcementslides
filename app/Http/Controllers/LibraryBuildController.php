<?php

namespace App\Http\Controllers;

use App\Jobs\SyncOverlayThumbnail;
use App\Models\LibraryItem;
use App\Models\Slide;
use App\Services\SlidePlacement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    public function build(Request $request, SlidePlacement $placement): JsonResponse
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

        $overlayPath = null;
        if ($template) {
            $source = $template->localPath();
            abort_unless($source, 422, 'The template file is missing.');
            $overlayPath = 'slides/' . Str::uuid() . '.svg';
            Storage::disk('public')->put($overlayPath, file_get_contents($source));
        }

        $slide = DB::transaction(function () use ($data, $user, $entityId, $background, $template, $overlayPath) {
            $slide = Slide::create([
                'title'       => $data['title'],
                'status'      => 'published',
                'uploaded_by' => $user->id,
                'entity_id'   => $entityId,
                'language_id' => $data['language_id'] ?? null,
            ]);

            if ($background) {
                // Same file, thumbnail and poster as the library item: no copy.
                $slide->media()->create([
                    'media_type'        => 'slide',
                    'filename'          => $background->filename,
                    'original_filename' => $background->original_filename,
                    'disk'              => $background->disk,
                    'disk_path'         => $background->disk_path,
                    'file_size'         => $background->file_size,
                    'mime_type'         => $background->mime_type,
                    'thumbnail_path'    => $background->thumbnail_path,
                    'poster_path'       => $background->poster_path,
                    'image_width'       => $background->image_width,
                    'image_height'      => $background->image_height,
                    'validation_issues' => [],
                    'validation_status' => 'ok',
                ]);
            }

            if ($template) {
                $slide->media()->create([
                    'media_type'        => 'slide-overlay',
                    'filename'          => basename($overlayPath),
                    'original_filename' => 'overlay.svg',
                    'disk_path'         => $overlayPath,
                    'file_size'         => $template->file_size,
                    'mime_type'         => 'image/svg+xml',
                    'overlay_settings'  => $template->overlay_settings,
                ]);
            }

            return $slide;
        });

        $template && SyncOverlayThumbnail::dispatch($slide->id);
        $placement->route($request, [$slide], $entityId);

        return response()->json(['slide_id' => $slide->id, 'has_overlay' => (bool) $template, 'entity_id' => $entityId]);
    }

    private function authorizeBuilder(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->adminEntities()->exists(), 403);
    }
}
