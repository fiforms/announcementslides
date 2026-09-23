<?php

namespace App\Http\Controllers\Concerns;

use App\Jobs\GenerateThumbnail;
use App\Jobs\SyncOverlayThumbnail;
use App\Models\Slide;
use App\Models\SlideMedia;
use App\Services\OverlaySource;
use App\Services\SvgSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        $fileSize = (int) $request->file_size;
        if ($request->mime_type === 'image/svg+xml' || str_ends_with($request->disk_path, '.svg')) {
            $fileSize = $this->sanitizeStoredSvg($request->disk_path);
        }

        $media = $slide->media()->create([
            'media_type'        => $mediaType,
            'filename'          => $request->filename,
            'original_filename' => $request->original_filename,
            'disk_path'         => $request->disk_path,
            'file_size'         => $fileSize,
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
     * SVG is served straight off the public disk, so a script inside one
     * would run when opened in a tab (e.g. MediaManager's Download link).
     * Rewrites the file in place with the sanitized markup; rejects (and
     * deletes) anything that isn't parseable SVG. Returns the new size.
     */
    private function sanitizeStoredSvg(string $diskPath): int
    {
        $disk = Storage::disk('public');
        $clean = app(SvgSanitizer::class)->sanitize($disk->get($diskPath));

        if ($clean === null) {
            $disk->delete($diskPath);
            throw ValidationException::withMessages(['file' => 'That file is not a valid SVG image.']);
        }

        $disk->put($diskPath, $clean);

        return strlen($clean);
    }

    /**
     * Loads the slide's current overlay for the overlay editor. `source` is
     * the embedded editor model, present only when the file was made by the
     * editor and hasn't been modified since (see OverlaySource). Otherwise
     * the overlay content is returned inline — sanitized SVG markup or a
     * raster data URI — so the editor can keep it as a fixed base layer
     * without a cross-origin fetch from the storage disk.
     */
    private function showOverlayForSlide(Slide $slide): JsonResponse
    {
        $media = $slide->overlayMedia;
        $disk = Storage::disk('public');

        if (!$media || !$disk->exists($media->disk_path)) {
            return response()->json(['source' => null, 'overlay' => null]);
        }

        $bytes = $disk->get($media->disk_path);

        if ($media->mime_type !== 'image/svg+xml') {
            return response()->json(['source' => null, 'overlay' => [
                'mime_type' => $media->mime_type,
                'data_uri'  => 'data:' . $media->mime_type . ';base64,' . base64_encode($bytes),
            ]]);
        }

        $extracted = app(OverlaySource::class)->extract($bytes);
        $svg = app(SvgSanitizer::class)->sanitize($extracted['svg']);

        return response()->json([
            'source'  => $svg === null ? null : $extracted['source'],
            'overlay' => $svg === null ? null : ['mime_type' => 'image/svg+xml', 'svg' => $svg],
        ]);
    }

    /**
     * Saves the overlay editor's output as the slide's (single) overlay:
     * the compiled SVG is sanitized, the editor source embedded into it, and
     * any previous overlay rows/files replaced.
     */
    private function saveOverlayForSlide(Request $request, Slide $slide): SlideMedia
    {
        $request->validate([
            'svg'    => 'required|string|max:8388608',
            'source' => 'required|string|max:1048576',
        ]);

        $source = json_decode($request->input('source'), true);
        if (!is_array($source) || !is_int($source['v'] ?? null) || !is_array($source['elements'] ?? null)) {
            throw ValidationException::withMessages(['source' => 'The overlay source is malformed.']);
        }

        $clean = app(SvgSanitizer::class)->sanitize($request->input('svg'));
        if ($clean === null) {
            throw ValidationException::withMessages(['svg' => 'The overlay is not a valid SVG image.']);
        }

        $svg = app(OverlaySource::class)->embed($clean, $source);
        $filename = Str::uuid() . '.svg';
        $diskPath = 'slides/' . $filename;
        $disk = Storage::disk('public');
        $disk->put($diskPath, $svg);

        $old = $slide->media()->where('media_type', 'slide-overlay')->get();

        $media = DB::transaction(function () use ($slide, $old, $filename, $diskPath, $svg) {
            $old->each->delete();

            return $slide->media()->create([
                'media_type'        => 'slide-overlay',
                'filename'          => $filename,
                'original_filename' => 'overlay.svg',
                'disk_path'         => $diskPath,
                'file_size'         => strlen($svg),
                'mime_type'         => 'image/svg+xml',
            ]);
        });

        $disk->delete($old->flatMap(fn ($m) => array_filter([$m->disk_path, $m->thumbnail_path]))->all());

        SyncOverlayThumbnail::dispatch($slide->id);

        return $media;
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
