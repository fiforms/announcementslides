<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data-only migration: copies each existing slide's single file's worth
     * of columns into a slide_media row of type 'slide'. Uses the query
     * builder rather than Eloquent so it neither depends on nor is broken by
     * later model changes. The column-drop happens in the next migration,
     * only after this backfill has run.
     */
    public function up(): void
    {
        DB::table('slides')->orderBy('id')->chunk(200, function ($slides) {
            $rows = $slides->map(fn ($slide) => [
                'slide_id'           => $slide->id,
                'media_type'         => 'slide',
                'filename'           => $slide->filename,
                'original_filename'  => $slide->original_filename,
                'disk_path'          => $slide->disk_path,
                'file_size'          => $slide->file_size,
                'mime_type'          => $slide->mime_type,
                'thumbnail_path'     => $slide->thumbnail_path,
                'image_width'        => $slide->image_width,
                'image_height'       => $slide->image_height,
                'validation_issues'  => $slide->validation_issues,
                'validation_status'  => $slide->validation_status ?? 'ok',
                'sort_order'         => 0,
                'created_at'         => $slide->created_at,
                'updated_at'         => $slide->updated_at,
            ])->all();

            DB::table('slide_media')->insert($rows);
        });
    }

    public function down(): void
    {
        DB::table('slide_media')->where('media_type', 'slide')->delete();
    }
};
