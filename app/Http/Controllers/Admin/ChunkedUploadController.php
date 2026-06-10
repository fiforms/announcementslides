<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateThumbnail;
use App\Models\Slide;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ChunkedUploadController extends Controller
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'video/mp4', 'video/quicktime', 'video/webm',
    ];

    public function chunk(Request $request)
    {
        $request->validate([
            'upload_id'    => ['required', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/'],
            'chunk_index'  => 'required|integer|min:0|max:9999',
            'total_chunks' => 'required|integer|min:1|max:10000',
            'filename'     => 'required|string|max:255',
            'mime_type'    => ['required', 'string', Rule::in(self::ALLOWED_MIME_TYPES)],
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

        // Clean up temp chunks
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

    public function finalize(Request $request)
    {
        $request->validate([
            'uploads'                     => 'required|array|min:1',
            'uploads.*.filename'          => ['required', 'string', 'regex:/^[0-9a-f\-]{36}\.[a-z0-9]+$/'],
            'uploads.*.disk_path'         => ['required', 'string', 'regex:/^slides\/[0-9a-f\-]{36}\.[a-z0-9]+$/'],
            'uploads.*.original_filename' => 'required|string|max:255',
            'uploads.*.file_size'         => 'required|integer|min:0',
            'uploads.*.mime_type'         => ['required', 'string', Rule::in(self::ALLOWED_MIME_TYPES)],
            'title'                       => 'required|string|max:255',
            'notes'                       => 'nullable|string',
            'publish_at'                  => 'nullable|date',
            'expires_at'                  => 'nullable|date|after_or_equal:publish_at',
            'status'                      => 'in:draft,published',
        ]);

        $slides = [];

        foreach ($request->uploads as $upload) {
            if (!Storage::disk('public')->exists($upload['disk_path'])) {
                return response()->json(['message' => 'Assembled file not found: ' . $upload['original_filename']], 422);
            }

            $slide = Slide::create([
                'title'             => $request->title,
                'notes'             => $request->notes,
                'filename'          => $upload['filename'],
                'original_filename' => $upload['original_filename'],
                'disk_path'         => $upload['disk_path'],
                'file_size'         => $upload['file_size'],
                'mime_type'         => $upload['mime_type'],
                'publish_at'        => $request->publish_at,
                'expires_at'        => $request->expires_at,
                'status'            => $request->status ?? 'published',
                'uploaded_by'       => $request->user()->id,
            ]);

            GenerateThumbnail::dispatch($slide);
            $slides[] = $slide;
        }

        return response()->json(['success' => true, 'count' => count($slides)]);
    }
}
