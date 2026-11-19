<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\BuildsOverlayFiles;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateLibraryThumbnail;
use App\Jobs\OffloadLibraryItem;
use App\Models\LibraryItem;
use App\Models\SlideMedia;
use App\Services\AudioProbe;
use App\Support\LibraryFiles;
use App\Support\MediaStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Library: the shared backgrounds and overlay templates church
 * leaders build slides from (see LibraryBuildController and "Slide library"
 * in ARCHITECTURE.md). A background is uploaded through the normal chunk
 * endpoint, then adopted here: hashed, stored once under its content address
 * and given a thumbnail. A template is an ordinary overlay made in the
 * overlay editor. Audio (a show's background music) is adopted the same way
 * but has no thumbnail.
 */
class LibraryController extends Controller
{
    use BuildsOverlayFiles;

    public function index(): Response
    {
        $items = LibraryItem::orderByDesc('created_at')->get();

        $uses = SlideMedia::whereIn('disk_path', $items->pluck('disk_path')->filter()->all())
            ->where('media_type', 'slide')
            ->selectRaw('disk_path, count(*) as n')->groupBy('disk_path')->pluck('n', 'disk_path');

        $audioUses = DB::table('show_audio')->selectRaw('library_item_id, count(distinct show_id) as n')
            ->groupBy('library_item_id')->pluck('n', 'library_item_id');

        return Inertia::render('Admin/Library/Index', [
            'audio'       => $items->where('kind', LibraryItem::AUDIO)->values()->map(fn ($i) => $this->resource($i, $audioUses[$i->id] ?? 0)),
            'backgrounds' => $items->where('kind', LibraryItem::BACKGROUND)->values()->map(fn ($i) => $this->resource($i, $uses[$i->disk_path] ?? 0)),
            'templates'   => $items->where('kind', LibraryItem::TEMPLATE)->values()->map(fn ($i) => $this->resource($i, 0)),
            'storage'     => ['offload' => app(MediaStore::class)->offloadEnabled()],
        ]);
    }

    /** Adopts a file already assembled by the chunk endpoint as a library background. */
    public function storeBackground(Request $request): RedirectResponse|JsonResponse
    {
        $ext = implode('|', array_map('preg_quote', array_values(array_intersect_key(
            config('slides.mime_extensions'), array_flip(config('slides.media_types.slide.mimes'))
        ))));

        $data = $request->validate([
            'upload'                   => 'required|array',
            'upload.disk_path'         => ['required', 'string', "regex:/^slides\/[0-9a-f\-]{36}\.({$ext})$/"],
            'upload.original_filename' => 'required|string|max:255',
            'upload.mime_type'         => ['required', 'string', Rule::in(config('slides.media_types.slide.mimes'))],
            'title'                    => 'nullable|string|max:255',
            'has_sound'                => 'nullable|boolean',
        ]);
        $upload = $data['upload'];
        $disk = Storage::disk(MediaStore::LOCAL);

        abort_unless($disk->exists($upload['disk_path']), 422, 'Assembled file not found.');

        $sha = hash_file('sha256', $disk->path($upload['disk_path']));
        $existing = LibraryItem::backgrounds()->where('sha256', $sha)->first();
        if ($existing) {
            // Same bytes already in the library: keep the one copy.
            $disk->delete($upload['disk_path']);

            return $this->done($request, 'duplicate', ['existing' => $existing->title]);
        }

        $ext = config("slides.mime_extensions.{$upload['mime_type']}");
        $dest = LibraryFiles::backgroundPath($sha, $ext);
        $disk->makeDirectory(dirname($dest));
        $disk->move($upload['disk_path'], $dest);

        $size = str_starts_with($upload['mime_type'], 'image/') ? @getimagesize($disk->path($dest)) : false;

        $item = LibraryItem::create([
            'kind'              => LibraryItem::BACKGROUND,
            'title'             => filled($data['title'] ?? null)
                ? $data['title']
                : trim(preg_replace('/[-_]+/', ' ', pathinfo($upload['original_filename'], PATHINFO_FILENAME))),
            'sha256'            => $sha,
            'filename'          => basename($dest),
            'original_filename' => $upload['original_filename'],
            'disk_path'         => $dest,
            'mime_type'         => $upload['mime_type'],
            'file_size'         => $disk->size($dest),
            'image_width'       => $size[0] ?? null,
            'image_height'      => $size[1] ?? null,
            'has_sound'         => (bool) ($data['has_sound'] ?? false),
            'created_by'        => $request->user()->id,
        ]);

        GenerateLibraryThumbnail::dispatch($item);

        return $this->done($request, 'added');
    }

    /** Adopts a file assembled by the chunk endpoint (media_type library-audio) as library audio. */
    public function storeAudio(Request $request, AudioProbe $probe): RedirectResponse|JsonResponse
    {
        $mimes = config('slides.library_audio.mimes');
        $ext = implode('|', array_map('preg_quote', array_map(fn ($m) => config("slides.mime_extensions.{$m}"), $mimes)));

        $data = $request->validate([
            'upload'                   => 'required|array',
            'upload.disk_path'         => ['required', 'string', "regex:/^slides\/[0-9a-f\-]{36}\.({$ext})$/"],
            'upload.original_filename' => 'required|string|max:255',
            'upload.mime_type'         => ['required', 'string', Rule::in($mimes)],
            'title'                    => 'nullable|string|max:255',
        ]);
        $upload = $data['upload'];
        $disk = Storage::disk(MediaStore::LOCAL);

        abort_unless($disk->exists($upload['disk_path']), 422, 'Assembled file not found.');

        $info = $probe->inspect($disk->path($upload['disk_path']));
        if ($info && !$info['playable']) {
            $disk->delete($upload['disk_path']);
            abort(422, 'That file does not contain playable audio.');
        }

        $sha = hash_file('sha256', $disk->path($upload['disk_path']));
        $existing = LibraryItem::audio()->where('sha256', $sha)->first();
        if ($existing) {
            $disk->delete($upload['disk_path']);

            return $this->done($request, 'duplicate', ['existing' => $existing->title]);
        }

        $dest = LibraryFiles::audioPath($sha, config("slides.mime_extensions.{$upload['mime_type']}"));
        $disk->makeDirectory(dirname($dest));
        $disk->move($upload['disk_path'], $dest);

        $item = LibraryItem::create([
            'kind'              => LibraryItem::AUDIO,
            'title'             => filled($data['title'] ?? null)
                ? $data['title']
                : trim(preg_replace('/[-_]+/', ' ', pathinfo($upload['original_filename'], PATHINFO_FILENAME))),
            'sha256'            => $sha,
            'filename'          => basename($dest),
            'original_filename' => $upload['original_filename'],
            'disk_path'         => $dest,
            'mime_type'         => $upload['mime_type'],
            'file_size'         => $disk->size($dest),
            'duration_seconds'  => $info['duration'] ?? null,
            'created_by'        => $request->user()->id,
        ]);

        if (app(MediaStore::class)->offloadEnabled()) {
            OffloadLibraryItem::dispatch($item);
        }

        return $this->done($request, 'added');
    }

    public function update(Request $request, LibraryItem $item): RedirectResponse
    {
        $data = $request->validate([
            'title'     => 'sometimes|required|string|max:255',
            'has_sound' => 'sometimes|boolean',
        ]);
        $item->update($data);

        // Slides built from this video share its file: keep their copy of the flag in step.
        if (array_key_exists('has_sound', $data) && $item->isVideo()) {
            SlideMedia::where('disk_path', $item->disk_path)->update(['muted' => !$item->has_sound]);
        }

        return back();
    }

    public function destroy(LibraryItem $item): RedirectResponse
    {
        // Slides already built from a background keep working: its file stays
        // until none of them use it (LibraryFiles::sweep).
        $item->delete();

        return back();
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $data = $request->validate(['title' => 'required|string|max:255']);
        $item = LibraryItem::create([
            'kind' => LibraryItem::TEMPLATE, 'title' => $data['title'], 'created_by' => $request->user()->id,
        ]);

        return redirect()->route('admin.library.templates.edit', $item);
    }

    public function editTemplate(LibraryItem $item): Response
    {
        abort_unless($item->isTemplate(), 404);

        return Inertia::render('Admin/Library/EditTemplate', [
            'template'    => $this->resource($item, 0),
            // Pictures to lay the template over while editing (a guide only).
            'backgrounds' => LibraryItem::backgrounds()->ready()->where('mime_type', 'like', 'image/%')->orderByDesc('created_at')->limit(24)->get()
                ->map(fn ($b) => ['id' => $b->id, 'title' => $b->title, 'url' => $b->file_url, 'thumbnail_url' => $b->thumbnail_url]),
        ]);
    }

    public function showOverlay(LibraryItem $item): JsonResponse
    {
        abort_unless($item->isTemplate(), 404);

        return $this->overlayEditorResponse($item->localPath(), $item->mime_type, $item->overlay_settings['widgets'] ?? []);
    }

    public function saveOverlay(Request $request, LibraryItem $item): RedirectResponse
    {
        abort_unless($item->isTemplate(), 404);

        [$svg, $settings] = $this->prepareOverlaySave($request);
        $path = LibraryFiles::PREFIX . 'templates/' . Str::uuid() . '.svg';
        Storage::disk(MediaStore::LOCAL)->put($path, $svg);

        $old = $item->disk_path;
        $item->update([
            'filename' => basename($path), 'original_filename' => 'overlay.svg', 'disk' => MediaStore::LOCAL, 'disk_path' => $path,
            'mime_type' => 'image/svg+xml', 'file_size' => strlen($svg),
            'overlay_settings' => $settings['widgets'] ? $settings : null,
        ]);
        $old && Storage::disk(MediaStore::LOCAL)->delete($old);

        return back()->with('success', 'Template saved.');
    }

    /** Clears the template's overlay (the editor's "remove overlay"). */
    public function destroyOverlay(LibraryItem $item): RedirectResponse
    {
        abort_unless($item->isTemplate(), 404);

        $item->disk_path && Storage::disk(MediaStore::LOCAL)->delete($item->disk_path);
        $item->update(['filename' => null, 'disk_path' => null, 'mime_type' => null, 'file_size' => 0, 'overlay_settings' => null]);

        return back();
    }

    private function done(Request $request, string $result, array $extra = []): RedirectResponse|JsonResponse
    {
        return $request->wantsJson() && !$request->header('X-Inertia')
            ? response()->json(['result' => $result] + $extra)
            : back()->with('library_result', ['result' => $result] + $extra);
    }

    private function resource(LibraryItem $i, int $uses): array
    {
        return [
            'id'            => $i->id,
            'kind'          => $i->kind,
            'title'         => $i->title,
            'file_url'      => $i->file_url,
            'preview_url'   => $i->preview_url,
            'thumbnail_url' => $i->thumbnail_url,
            'mime_type'     => $i->mime_type,
            'is_video'      => $i->isVideo(),
            'has_sound'     => (bool) $i->has_sound,
            'duration_seconds' => $i->duration_seconds,
            'file_size'     => $i->file_size,
            'image_width'   => $i->image_width,
            'image_height'  => $i->image_height,
            'ready'         => $i->isTemplate() || $i->isAudio() ? (bool) $i->disk_path : (bool) $i->thumbnail_path,
            'offloaded'     => $i->disk !== MediaStore::LOCAL,
            'uses'          => $uses,
            'created_at'    => $i->created_at?->toIso8601String(),
        ];
    }
}
