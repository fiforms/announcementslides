<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SlideAnnouncerRelease;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SlideAnnouncerReleaseController extends Controller
{
    // No real browser-recognized MIME type exists for .raucb, and .tar.gz
    // varies by browser/OS (application/gzip, application/x-gzip, or none
    // at all) — extension is the only reliable signal here, unlike the
    // image/video allowlists ChunkedUploadController checks by MIME.
    private const ALLOWED_EXTENSIONS = ['raucb', 'tar.gz'];

    public function index(): Response
    {
        $releases = SlideAnnouncerRelease::with('creator')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SlideAnnouncerRelease $release) => $this->releaseResource($release));

        return Inertia::render('Admin/SlideAnnouncerReleases', [
            'releases' => $releases,
        ]);
    }

    public function chunk(Request $request)
    {
        $request->validate([
            'upload_id' => ['required', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/'],
            'chunk_index' => 'required|integer|min:0|max:99999',
            'total_chunks' => 'required|integer|min:1|max:100000',
            'filename' => 'required|string|max:255',
            'chunk' => 'required|file',
        ]);

        $extension = $this->matchedExtension($request->filename);
        if (! $extension) {
            return response()->json([
                'message' => 'Only .raucb and .tar.gz files are accepted.',
            ], 422);
        }

        $uploadId = $request->upload_id;
        $chunkIndex = (int) $request->chunk_index;
        $totalChunks = (int) $request->total_chunks;
        $chunkDir = storage_path("app/chunks/{$uploadId}");

        if (! is_dir($chunkDir)) {
            mkdir($chunkDir, 0755, true);
        }

        $request->file('chunk')->move($chunkDir, "chunk_{$chunkIndex}");

        $receivedChunks = count(glob("{$chunkDir}/chunk_*"));
        if ($receivedChunks < $totalChunks) {
            return response()->json(['status' => 'partial', 'received' => $receivedChunks]);
        }

        // All chunks received — assemble into a staging path. Final
        // placement under slide-announcer/releases/{kind}/{channel}/... is
        // finalize()'s job, once it knows those (chunk() only ever sees a
        // filename), matching ChunkedUploadController's own two-step split.
        $uuid = (string) Str::uuid();
        $stagedName = "{$uuid}.{$extension}";

        Storage::disk('public')->makeDirectory('slide-announcer/uploads');
        $destPath = Storage::disk('public')->path("slide-announcer/uploads/{$stagedName}");

        $dest = fopen($destPath, 'wb');
        for ($i = 0; $i < $totalChunks; $i++) {
            $fp = fopen("{$chunkDir}/chunk_{$i}", 'rb');
            while (! feof($fp)) {
                fwrite($dest, fread($fp, 65536));
            }
            fclose($fp);
        }
        fclose($dest);

        array_map('unlink', glob("{$chunkDir}/chunk_*"));
        rmdir($chunkDir);

        return response()->json([
            'status' => 'complete',
            'disk_path' => "slide-announcer/uploads/{$stagedName}",
            'original_filename' => $request->filename,
            'file_size' => filesize($destPath),
        ]);
    }

    public function finalize(Request $request)
    {
        $data = $request->validate([
            // Shares useChunkedUpload.js's generic "uploads" array contract
            // with ChunkedUploadController, even though a release is always
            // exactly one file.
            'uploads' => 'required|array|size:1',
            'uploads.0.disk_path' => ['required', 'string', 'regex:#^slide-announcer/uploads/[0-9a-f\-]{36}\.(raucb|tar\.gz)$#'],
            'kind' => ['required', 'string', Rule::in(SlideAnnouncerRelease::KINDS)],
            'version' => 'required|string|max:255',
            'channel' => 'required|in:stable,testing,developer',
            'notes' => 'nullable|string',
            'activate' => 'boolean',
        ]);

        $diskPath = $data['uploads'][0]['disk_path'];

        if (! Storage::disk('public')->exists($diskPath)) {
            return response()->json(['message' => 'Assembled file not found — please re-upload.'], 422);
        }

        $extension = str_ends_with($diskPath, '.tar.gz') ? 'tar.gz' : 'raucb';
        $finalPath = "slide-announcer/releases/{$data['kind']}/{$data['channel']}/{$data['version']}.{$extension}";

        $sha256 = hash_file('sha256', Storage::disk('public')->path($diskPath));

        // move() is a plain rename on the local driver (instant regardless
        // of file size) and a server-side copy+delete on S3/R2 — either
        // way, no re-upload through this app for a multi-GB file.
        Storage::disk('public')->move($diskPath, $finalPath);

        $release = SlideAnnouncerRelease::create([
            'kind' => $data['kind'],
            'version' => $data['version'],
            'channel' => $data['channel'],
            'disk_path' => $finalPath,
            'sha256' => $sha256,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        if ($request->boolean('activate')) {
            $release->activate();
        }

        return response()->json(['success' => true, 'release' => $this->releaseResource($release->fresh())]);
    }

    public function activate(Request $request, SlideAnnouncerRelease $slideAnnouncerRelease)
    {
        $slideAnnouncerRelease->activate();

        return back()->with('success', "Activated {$slideAnnouncerRelease->kind} {$slideAnnouncerRelease->version} on {$slideAnnouncerRelease->channel}.");
    }

    public function destroy(SlideAnnouncerRelease $slideAnnouncerRelease)
    {
        abort_if($slideAnnouncerRelease->is_active, 422, 'Deactivate this release before deleting it.');

        Storage::disk('public')->delete($slideAnnouncerRelease->disk_path);
        $slideAnnouncerRelease->delete();

        return back()->with('success', 'Release deleted.');
    }

    /**
     * Returns the matched extension ('raucb' or 'tar.gz') or null — checks
     * the multi-part '.tar.gz' case first since pathinfo()-style
     * single-extension logic would only see '.gz'.
     */
    private function matchedExtension(string $filename): ?string
    {
        $lower = strtolower($filename);
        foreach (self::ALLOWED_EXTENSIONS as $ext) {
            if (str_ends_with($lower, ".{$ext}")) {
                return $ext;
            }
        }
        return null;
    }

    private function releaseResource(SlideAnnouncerRelease $release): array
    {
        return [
            'id' => $release->id,
            'kind' => $release->kind,
            'version' => $release->version,
            'channel' => $release->channel,
            'is_active' => $release->is_active,
            'sha256' => $release->sha256,
            'notes' => $release->notes,
            'file_size' => Storage::disk('public')->exists($release->disk_path)
                ? Storage::disk('public')->size($release->disk_path)
                : null,
            'released_at' => $release->released_at?->toIso8601String(),
            'created_at' => $release->created_at->toIso8601String(),
            'creator' => $release->creator?->only('id', 'name'),
        ];
    }
}
