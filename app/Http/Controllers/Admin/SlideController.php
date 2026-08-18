<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManagesSlideMedia;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ShowController;
use App\Jobs\GenerateThumbnail;
use App\Jobs\SyncShowAutoFillForSlide;
use App\Models\GlobalShowTemplate;
use App\Models\Language;
use App\Models\Show;
use App\Models\Slide;
use App\Models\SlideMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SlideController extends Controller
{
    use ManagesSlideMedia;

    public function index(): Response
    {
        $with     = ['uploader', 'entity', 'primaryMedia'];
        // Entity-scoped (local) slides are managed under Entity Slides; only show
        // global slides here, ordered by the Global Board (the master
        // ordering fanned out to every entity's Main show by default).
        $current  = Slide::with($with)->unscoped()->current()->orderedInShow(Show::globalBoard()->id)->get()->map(fn ($s) => $this->slideResource($s));
        $pending  = Slide::with($with)->unscoped()->pendingReview()->orderByDesc('created_at')->get()->map(fn ($s) => $this->slideResource($s));
        $upcoming = Slide::with($with)->unscoped()->upcoming()->orderBy('publish_at')->get()->map(fn ($s) => $this->slideResource($s));
        $archived = Slide::with($with)->unscoped()->archived()->orderByDesc('expires_at')->limit(20)->get()->map(fn ($s) => $this->slideResource($s));
        $drafts   = Slide::with($with)->unscoped()->where('status', 'draft')->orderByDesc('created_at')->get()->map(fn ($s) => $this->slideResource($s));

        $languages = Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']);
        $globalShowTemplates = GlobalShowTemplate::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Admin/Slides/Index', compact('current', 'pending', 'upcoming', 'archived', 'drafts', 'languages', 'globalShowTemplates'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'files'            => 'required|array|min:1',
            'files.*'          => 'required|file|mimes:jpeg,jpg,png,webp,gif,mp4,mov,webm|max:204800',
            'title'            => 'required|string|max:255',
            'notes'            => 'nullable|string',
            'text_description' => 'nullable|string',
            'link'             => 'nullable|url|max:2048',
            'language_id'      => 'nullable|integer|exists:languages,id',
            'publish_at'       => 'nullable|date',
            'expires_at'       => 'nullable|date|after_or_equal:publish_at',
            'status'           => 'in:draft,published',
        ]);

        $slides = [];

        foreach ($request->file('files') as $file) {
            $uuid       = Str::uuid();
            $ext        = $file->getClientOriginalExtension();
            $filename   = "{$uuid}.{$ext}";
            $diskPath   = "slides/{$filename}";

            $file->storeAs('slides', $filename, 'public');

            $slide = Slide::create([
                'title'             => $request->title,
                'notes'             => $request->notes,
                'text_description'  => $request->text_description,
                'link'              => $request->link,
                'language_id'       => $request->language_id,
                'publish_at'        => $request->publish_at,
                'expires_at'        => $request->expires_at,
                'status'            => $request->status ?? 'published',
                'uploaded_by'       => $request->user()->id,
            ]);

            $media = $slide->media()->create([
                'media_type'        => 'slide',
                'filename'          => $filename,
                'original_filename' => $file->getClientOriginalName(),
                'disk_path'         => $diskPath,
                'file_size'         => $file->getSize(),
                'mime_type'         => $file->getMimeType(),
            ]);

            GenerateThumbnail::dispatch($media);
            $slides[] = $slide;
        }

        return redirect()->route('admin.slides.index')
            ->with('success', count($slides) . ' slide(s) uploaded successfully.');
    }

    public function edit(Slide $slide): Response
    {
        $slide->load('media');
        $languages = Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']);

        return Inertia::render('Admin/Slides/Edit', [
            'slide' => $this->slideResource($slide, withMedia: true),
            'languages' => $languages,
            'mediaTypes' => $this->mediaTypesForFrontend(),
        ]);
    }

    public function update(Request $request, Slide $slide)
    {
        $request->validate([
            'title'               => 'required|string|max:255',
            'notes'               => 'nullable|string',
            'text_description'    => 'nullable|string',
            'link'                => 'nullable|url|max:2048',
            'video_playback_mode' => 'nullable|in:play_through,hold_last_frame,loop',
            'language_id'         => 'nullable|integer|exists:languages,id',
            'publish_at'          => 'nullable|date',
            'expires_at'          => 'nullable|date',
            'status'              => 'required|in:draft,pending,published,rejected',
            'entity_id'           => 'nullable|integer|exists:entities,id',
            'share_nearby'        => 'boolean',
        ]);

        $newLanguageId = $request->filled('language_id') ? (int) $request->input('language_id') : null;
        $languageChanged = $slide->language_id !== $newLanguageId;

        $slide->update($request->only('title', 'notes', 'text_description', 'link', 'video_playback_mode', 'language_id', 'publish_at', 'expires_at', 'status', 'entity_id', 'share_nearby'));

        // Re-run auto-fill fan-out so language-gated shows pick up/drop this
        // slide to match its new tag — never touches a leader's manual keep.
        if ($languageChanged && $slide->entity_id === null) {
            SyncShowAutoFillForSlide::dispatch($slide->id);
        }

        return redirect()->route('admin.slides.index')
            ->with('success', 'Slide updated.');
    }

    public function storeMedia(Request $request, Slide $slide)
    {
        $this->storeMediaForSlide($request, $slide);

        return back()->with('success', 'Media added.');
    }

    public function destroyMedia(Slide $slide, SlideMedia $media)
    {
        $this->destroyMediaForSlide($slide, $media);

        return back()->with('success', 'Media removed.');
    }

    public function approve(Slide $slide)
    {
        $slide->update([
            'status'      => 'published',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Slide approved and published.');
    }

    public function reject(Slide $slide)
    {
        $slide->update([
            'status'      => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Slide rejected.');
    }

    public function reorder(Request $request)
    {
        $request->validate(['order' => 'required|array', 'order.*' => 'integer']);

        ShowController::persistOrder(Show::globalBoard(), $request->order);

        return response()->json(['ok' => true]);
    }

    public function archive(Slide $slide)
    {
        $slide->update(['expires_at' => now()]);

        return back()->with('success', 'Slide archived.');
    }

    public function unarchive(Slide $slide)
    {
        $slide->update(['expires_at' => null]);

        return back()->with('success', 'Slide restored.');
    }

    public function destroy(Slide $slide)
    {
        $slide->delete();

        return back()->with('success', 'Slide removed.');
    }

    private function slideResource(Slide $slide, bool $withMedia = false): array
    {
        return [
            'id'                => $slide->id,
            'title'             => $slide->title,
            'notes'             => $slide->notes,
            'text_description'  => $slide->text_description,
            'link'              => $slide->link,
            'video_playback_mode' => $slide->video_playback_mode,
            'language_id'       => $slide->language_id,
            'mime_type'         => $slide->mime_type,
            'file_url'          => $slide->file_url,
            'thumbnail_url'     => $slide->thumbnail_url,
            'publish_at'        => $slide->publish_at?->toIso8601String(),
            'expires_at'        => $slide->expires_at?->toIso8601String(),
            'status'            => $slide->status,
            'share_nearby'      => $slide->share_nearby,
            'original_filename' => $slide->original_filename,
            'file_size'         => $slide->file_size,
            'validation_issues' => $slide->validation_issues,
            'validation_status' => $slide->validation_status,
            'uploader'          => $slide->uploader?->only('id', 'name'),
            'entity'            => $slide->entity ? ['id' => $slide->entity->id, 'name' => $slide->entity->name] : null,
            'created_at'        => $slide->created_at->toIso8601String(),
            'media'             => $withMedia ? $this->mediaResource($slide) : null,
        ];
    }
}
