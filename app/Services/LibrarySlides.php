<?php

namespace App\Services;

use App\Jobs\SyncOverlayThumbnail;
use App\Models\LibraryItem;
use App\Models\Slide;
use App\Models\SlideMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Puts library items onto a slide: a background becomes the slide's `slide`
 * media row pointing at the library's one stored file (nothing copied), a
 * template becomes the slide's own copy of the overlay SVG and its widget
 * placements. Used when building a slide and when adding from the library
 * in the slide editor, where `$replace` swaps out the slide's current file
 * of that type.
 */
class LibrarySlides
{
    public function attachBackground(Slide $slide, LibraryItem $item, bool $replace = false): SlideMedia
    {
        $old = $replace ? $slide->media()->where('media_type', 'slide')->get() : collect();

        $media = DB::transaction(function () use ($slide, $item, $old) {
            $old->each->delete();

            return $slide->media()->create([
                'media_type'        => 'slide',
                'filename'          => $item->filename,
                'original_filename' => $item->original_filename,
                'disk'              => $item->disk,
                'disk_path'         => $item->disk_path,
                'file_size'         => $item->file_size,
                'mime_type'         => $item->mime_type,
                'thumbnail_path'    => $item->thumbnail_path,
                'poster_path'       => $item->poster_path,
                'image_width'       => $item->image_width,
                'image_height'      => $item->image_height,
                'validation_issues' => [],
                'validation_status' => 'ok',
            ]);
        });
        $old->each->deleteFiles();

        // A library video is a looping backdrop: keep it running for the whole slide delay.
        if ($item->isVideo()) {
            $slide->update(['video_playback_mode' => 'loop']);
        }

        SyncOverlayThumbnail::dispatch($slide->id);

        return $media;
    }

    public function attachTemplate(Slide $slide, LibraryItem $item, bool $replace = false): SlideMedia
    {
        $source = $item->localPath();
        abort_unless($source, 422, 'The template file is missing.');

        $path = 'slides/' . Str::uuid() . '.svg';
        Storage::disk('public')->put($path, file_get_contents($source));

        $old = $replace ? $slide->media()->where('media_type', 'slide-overlay')->get() : collect();

        $media = DB::transaction(function () use ($slide, $item, $path, $old) {
            $old->each->delete();

            return $slide->media()->create([
                'media_type'        => 'slide-overlay',
                'filename'          => basename($path),
                'original_filename' => 'overlay.svg',
                'disk_path'         => $path,
                'file_size'         => $item->file_size,
                'mime_type'         => 'image/svg+xml',
                'overlay_settings'  => $item->overlay_settings,
            ]);
        });
        $old->each->deleteFiles();

        SyncOverlayThumbnail::dispatch($slide->id);

        return $media;
    }
}
