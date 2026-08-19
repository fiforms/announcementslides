<?php

namespace App\Http\Controllers\Concerns;

use App\Jobs\GenerateThumbnail;
use App\Jobs\SyncOverlayThumbnail;
use App\Models\Slide;
use App\Models\SlideMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Shared "attach/remove an additional media file on an existing slide" logic
 * used by every area's slide controller (Admin, My Slides, Local Slides,
 * Entity). The initial upload always seeds a slide's primary 'slide' media
 * row (see ChunkedUploadController::finalize); these actions add/remove the
 * optional variants (slide-overlay, color-flyer, easy-print-flyer,
 * social-media-image) from the Edit screens.
 */
trait ManagesSlideMedia
{
    private function mediaTypesForFrontend(): array
    {
        return collect(config('slides.media_types'))
            ->map(fn ($config, $type) => [
                'value' => $type,
                'label' => $config['label'],
                'accept' => implode(',', $config['mimes']),
            ])
            ->values()
            ->all();
    }

    private function storeMediaForSlide(Request $request, Slide $slide): SlideMedia
    {
        $mediaType = $request->input('media_type');
        $allowedMimes = config("slides.media_types.{$mediaType}.mimes", []);

        $request->validate([
            'media_type'         => ['required', 'string', Rule::in(array_keys(config('slides.media_types')))],
            'filename'           => ['required', 'string', 'regex:/^[0-9a-f\-]{36}\.[a-z0-9]+$/'],
            'disk_path'          => ['required', 'string', 'regex:/^slides\/[0-9a-f\-]{36}\.[a-z0-9]+$/'],
            'original_filename'  => 'required|string|max:255',
            'file_size'          => 'required|integer|min:0',
            'mime_type'          => ['required', 'string', Rule::in($allowedMimes)],
        ]);

        abort_unless(Storage::disk('public')->exists($request->disk_path), 422, 'Assembled file not found.');

        $media = $slide->media()->create([
            'media_type'        => $mediaType,
            'filename'          => $request->filename,
            'original_filename' => $request->original_filename,
            'disk_path'         => $request->disk_path,
            'file_size'         => $request->file_size,
            'mime_type'         => $request->mime_type,
        ]);

        GenerateThumbnail::dispatch($media);

        return $media;
    }

    private function destroyMediaForSlide(Slide $slide, SlideMedia $media): void
    {
        abort_unless($media->slide_id === $slide->id, 404);

        if ($media->media_type === 'slide' && $slide->media()->where('media_type', 'slide')->count() <= 1) {
            abort(422, 'A slide must keep at least one "slide" media file.');
        }

        Storage::disk('public')->delete(array_filter([$media->disk_path, $media->thumbnail_path]));
        $media->delete();

        SyncOverlayThumbnail::dispatch($slide->id);
    }

    /**
     * Serializes a slide's attached media for Edit-page responses (list
     * views intentionally omit this to keep those payloads light).
     */
    private function mediaResource(Slide $slide): array
    {
        return $slide->media->map(fn (SlideMedia $m) => [
            'id'                => $m->id,
            'media_type'        => $m->media_type,
            'file_url'          => $m->file_url,
            'thumbnail_url'     => $m->thumbnail_url,
            'mime_type'         => $m->mime_type,
            'original_filename' => $m->original_filename,
            'file_size'         => $m->file_size,
            'validation_status' => $m->validation_status,
        ])->all();
    }
}
