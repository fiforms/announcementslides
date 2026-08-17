<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateThumbnail;
use App\Models\Slide;
use App\Services\ImageValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ChunkedUploadController extends Controller
{
    public function chunk(Request $request)
    {
        $mediaType = $request->input('media_type', 'slide');
        $allowedMimes = config("slides.media_types.{$mediaType}.mimes", []);

        $request->validate([
            'upload_id'    => ['required', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/'],
            'chunk_index'  => 'required|integer|min:0|max:9999',
            'total_chunks' => 'required|integer|min:1|max:10000',
            'filename'     => 'required|string|max:255',
            'media_type'   => ['nullable', 'string', Rule::in(array_keys(config('slides.media_types')))],
            'mime_type'    => ['required', 'string', Rule::in($allowedMimes)],
            'chunk'        => 'required|file',
        ]);

        $uploadId    = $request->upload_id;
        $chunkIndex  = (int) $request->chunk_index;
        $totalChunks = (int) $request->total_chunks;
        $chunkDir    = storage_path("app/chunks/{$uploadId}");

        if (!is_dir($chunkDir)) {
            mkdir($chunkDir, 0755, true);
        }

        $request->file('chunk')->move($chunkDir, "chunk_{$chunkIndex}");

        $receivedChunks = count(glob("{$chunkDir}/chunk_*"));
        if ($receivedChunks < $totalChunks) {
            return response()->json(['status' => 'partial', 'received' => $receivedChunks]);
        }

        // All chunks received — assemble the file
        $ext      = strtolower(pathinfo($request->filename, PATHINFO_EXTENSION));
        $uuid     = (string) Str::uuid();
        $filename = "{$uuid}.{$ext}";

        Storage::disk('public')->makeDirectory('slides');
        $destPath = Storage::disk('public')->path("slides/{$filename}");

        $dest = fopen($destPath, 'wb');
        for ($i = 0; $i < $totalChunks; $i++) {
            $fp = fopen("{$chunkDir}/chunk_{$i}", 'rb');
            while (!feof($fp)) {
                fwrite($dest, fread($fp, 65536));
            }
            fclose($fp);
        }
        fclose($dest);

        array_map('unlink', glob("{$chunkDir}/chunk_*"));
        rmdir($chunkDir);

        return response()->json([
            'status'            => 'complete',
            'filename'          => $filename,
            'disk_path'         => "slides/{$filename}",
            'original_filename' => $request->filename,
            'file_size'         => filesize($destPath),
            'mime_type'         => $request->mime_type,
        ]);
    }

    public function finalize(Request $request, ImageValidationService $validationService)
    {
        $request->validate([
            'uploads'                     => 'required|array|min:1',
            'uploads.*.filename'          => ['required', 'string', 'regex:/^[0-9a-f\-]{36}\.[a-z0-9]+$/'],
            'uploads.*.disk_path'         => ['required', 'string', 'regex:/^slides\/[0-9a-f\-]{36}\.[a-z0-9]+$/'],
            'uploads.*.original_filename' => 'required|string|max:255',
            'uploads.*.file_size'         => 'required|integer|min:0',
            'uploads.*.mime_type'         => ['required', 'string', Rule::in(config('slides.media_types.slide.mimes'))],
            'title'                       => 'required|string|max:255',
            'notes'                       => 'nullable|string',
            'text_description'            => 'nullable|string',
            'link'                        => 'nullable|url|max:2048',
            'language_id'                 => 'nullable|integer|exists:languages,id',
            'publish_at'                  => 'nullable|date',
            'expires_at'                  => 'nullable|date|after_or_equal:publish_at',
            'status'                      => 'in:draft,pending,published',
            'entity_id'                   => 'nullable|integer|exists:entities,id',
            'share_nearby'                => 'boolean',
        ]);

        $user     = $request->user();
        $entityId = null;
        $status   = 'published';

        if ($request->entity_id) {
            abort_unless($user->isAdmin() || $user->isEntityAdmin((int) $request->entity_id), 403);
            $entityId = (int) $request->entity_id;
            $status   = 'published';
        } elseif ($user->isAdmin()) {
            $status   = $request->status ?? 'published';
            $entityId = null;
        } elseif ($user->isContributor()) {
            $status   = in_array($request->status, ['draft', 'pending', 'published'], true)
                ? $request->status
                : 'published';
            $entityId = null;
        } else {
            // Viewer — goes to pending inbox
            $status   = 'pending';
            $entityId = null;
        }

        // Nearby sharing only applies to entity-scoped (local) slides.
        $shareNearby = $entityId !== null && $request->boolean('share_nearby');

        // Contributors and viewers may not publish/submit global slides (no
        // entity) that fail any quality check — those are hard-blocked rather
        // than flagged. Admins and (non-shared) entity uploads keep the
        // soft-warning path. Local slides marked to share with nearby churches
        // are held to the same hard quality bar, since they appear on other
        // congregations' dashboards.
        $enforceQuality = ($entityId === null && !$user->isAdmin()) || $shareNearby;

        // First pass: validate every upload before creating anything, so one
        // failing file rejects the whole batch instead of publishing partially.
        $validated = [];
        $blocked   = [];

        foreach ($request->uploads as $upload) {
            if (!Storage::disk('public')->exists($upload['disk_path'])) {
                return response()->json(['message' => 'Assembled file not found: ' . $upload['original_filename']], 422);
            }

            $filePath = Storage::disk('public')->path($upload['disk_path']);
            $validation = $validationService->validate($filePath, $upload['mime_type'], $upload['file_size']);

            if ($enforceQuality && $validation['status'] !== 'ok') {
                $blocked[$upload['original_filename']] = $validation['issues'];
            }

            $validated[] = [$upload, $validation];
        }

        if (!empty($blocked)) {
            // Remove the orphaned assembled files — there is no slide record to
            // own them, and the user must upload an acceptable replacement.
            foreach ($request->uploads as $upload) {
                Storage::disk('public')->delete($upload['disk_path']);
            }

            return response()->json([
                'message' => $this->qualityBlockMessage($blocked),
                'blocked' => $blocked,
            ], 422);
        }

        $slides = [];

        foreach ($validated as [$upload, $validation]) {
            $slide = Slide::create([
                'title'             => $request->title,
                'notes'             => $request->notes,
                'text_description'  => $request->text_description,
                'link'              => $request->link,
                'publish_at'        => $request->publish_at,
                'expires_at'        => $request->expires_at,
                'status'            => $status,
                'uploaded_by'       => $user->id,
                'entity_id'         => $entityId,
                'language_id'       => $request->language_id,
                'share_nearby'      => $shareNearby,
            ]);

            $media = $slide->media()->create([
                'media_type'        => 'slide',
                'filename'          => $upload['filename'],
                'original_filename' => $upload['original_filename'],
                'disk_path'         => $upload['disk_path'],
                'file_size'         => $upload['file_size'],
                'mime_type'         => $upload['mime_type'],
                'image_width'       => $validation['width'],
                'image_height'      => $validation['height'],
                'validation_issues' => $validation['issues'],
                'validation_status' => $validation['status'],
            ]);

            GenerateThumbnail::dispatch($media);
            $slides[] = $slide;
        }

        return response()->json(['success' => true, 'count' => count($slides), 'status' => $status]);
    }

    /**
     * Build a human-readable rejection message listing why each file was blocked.
     *
     * @param  array<string, string[]>  $blocked  filename => list of issues
     */
    private function qualityBlockMessage(array $blocked): string
    {
        $intro = count($blocked) === 1
            ? 'This file does not meet the quality requirements and was not accepted:'
            : 'These files do not meet the quality requirements and were not accepted:';

        $lines = [];
        foreach ($blocked as $filename => $issues) {
            $lines[] = $filename . ' — ' . implode('; ', $issues);
        }

        return $intro . ' ' . implode(' | ', $lines);
    }
}
