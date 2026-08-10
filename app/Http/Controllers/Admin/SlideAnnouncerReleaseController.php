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
    // No real browser-recognized MIME type exists for .raucb, and .tar.gz/
    // .img.xz vary by browser/OS (application/gzip, application/x-gzip, or
    // none at all) — extension is the only reliable signal here, unlike
    // the image/video allowlists ChunkedUploadController checks by MIME.
    private const ALLOWED_EXTENSIONS = ['raucb', 'tar.gz', 'img.xz'];

    public function index(): Response
    {
        $releases = SlideAnnouncerRelease::with(['creator', 'channels.taggedBy'])
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
                'message' => 'Only .raucb, .tar.gz, and .img.xz files are accepted.',
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
        // placement under slide-announcer/releases/{kind}/{architecture}/...
        // is finalize()'s job, once it knows those (chunk() only ever sees
        // a filename), matching ChunkedUploadController's own two-step split.
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
            'uploads.0.disk_path' => ['required', 'string', 'regex:#^slide-announcer/uploads/[0-9a-f\-]{36}\.(raucb|tar\.gz|img\.xz)$#'],
            'kind' => ['required', 'string', Rule::in(SlideAnnouncerRelease::KINDS)],
            'release_type' => ['required', 'string', Rule::in(SlideAnnouncerRelease::RELEASE_TYPES)],
            'version' => ['required', 'string', 'regex:/^\d+\.\d+\.\d+$/'],
            'required_base_version' => ['required_if:release_type,hotfix', 'nullable', 'string', 'regex:/^\d+\.\d+\.\d+$/'],
            'architecture' => 'required|string|max:64',
            // Optional initial tag — a release can be published untagged
            // (archived from the moment it lands) or tagged immediately,
            // same as tagging it later via the tag() endpoint.
            'channel' => ['nullable', Rule::in(SlideAnnouncerRelease::CHANNELS)],
            'notes' => 'nullable|string',
        ]);

        // disk_image is an os-only flashable archive, never an app variant;
        // hotfix requires a base version, full/disk_image must not have one.
        if ($data['release_type'] === 'disk_image' && $data['kind'] !== 'os') {
            return response()->json(['message' => 'Disk images are only valid for the os kind.'], 422);
        }
        if ($data['release_type'] !== 'hotfix' && ! empty($data['required_base_version'])) {
            return response()->json(['message' => 'Only a hotfix may specify a required base version.'], 422);
        }

        $diskPath = $data['uploads'][0]['disk_path'];

        if (! Storage::disk('public')->exists($diskPath)) {
            return response()->json(['message' => 'Assembled file not found — please re-upload.'], 422);
        }

        $extension = $this->matchedExtension($diskPath);
        $expectedExtension = $this->expectedExtension($data['kind'], $data['release_type']);
        if ($extension !== $expectedExtension) {
            return response()->json([
                'message' => "A {$data['kind']} {$data['release_type']} release must be a .{$expectedExtension} file.",
            ], 422);
        }

        // Suffix keeps the path collision-free now that a hotfix's target
        // version can coincide with a full release's version number, or
        // with a disk image built for the same version.
        $suffix = match ($data['release_type']) {
            'hotfix' => "-hotfix-from-{$data['required_base_version']}",
            'disk_image' => '-disk-image',
            default => '',
        };
        $finalPath = "slide-announcer/releases/{$data['kind']}/{$data['architecture']}/{$data['version']}{$suffix}.{$extension}";

        $sha256 = hash_file('sha256', Storage::disk('public')->path($diskPath));

        // move() is a plain rename on the local driver (instant regardless
        // of file size) and a server-side copy+delete on S3/R2 — either
        // way, no re-upload through this app for a multi-GB file.
        Storage::disk('public')->move($diskPath, $finalPath);

        $release = SlideAnnouncerRelease::create([
            'kind' => $data['kind'],
            'version' => $data['version'],
            'architecture' => $data['architecture'],
            'release_type' => $data['release_type'],
            'required_base_version' => $data['release_type'] === 'hotfix' ? $data['required_base_version'] : null,
            'disk_path' => $finalPath,
            'sha256' => $sha256,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        if (! empty($data['channel'])) {
            $release->tagChannel($data['channel'], $request->user()->id);
        }

        return response()->json(['success' => true, 'release' => $this->releaseResource($release->fresh('channels'))]);
    }

    public function tag(Request $request, SlideAnnouncerRelease $slideAnnouncerRelease)
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(SlideAnnouncerRelease::CHANNELS)],
        ]);

        $slideAnnouncerRelease->tagChannel($data['channel'], $request->user()->id);

        return back()->with('success', "Tagged {$slideAnnouncerRelease->kind} {$slideAnnouncerRelease->version} ({$slideAnnouncerRelease->architecture}) as {$data['channel']}.");
    }

    public function untag(Request $request, SlideAnnouncerRelease $slideAnnouncerRelease, string $channel)
    {
        abort_unless(in_array($channel, SlideAnnouncerRelease::CHANNELS, true), 404);

        $slideAnnouncerRelease->untagChannel($channel);

        return back()->with('success', "Removed the {$channel} tag from {$slideAnnouncerRelease->kind} {$slideAnnouncerRelease->version}.");
    }

    public function destroy(SlideAnnouncerRelease $slideAnnouncerRelease)
    {
        abort_if($slideAnnouncerRelease->channels()->exists(), 422, 'Untag this release from every channel before deleting it.');

        Storage::disk('public')->delete($slideAnnouncerRelease->disk_path);
        $slideAnnouncerRelease->delete();

        return back()->with('success', 'Release deleted.');
    }

    /**
     * (os,full) and (os,hotfix) are RAUC bundles; (os,disk_image) is a
     * flashable .img.xz disk image; (app,full) is the local-app .tar.gz
     * archive. Only these four combinations are valid — enforced by
     * finalize()'s disk_image kind check above.
     */
    private function expectedExtension(string $kind, string $releaseType): string
    {
        return $kind === 'os' && $releaseType === 'disk_image' ? 'img.xz' : ($kind === 'os' ? 'raucb' : 'tar.gz');
    }

    /**
     * Returns the matched extension ('raucb', 'tar.gz', or 'img.xz') or
     * null — checks the multi-part extensions first since pathinfo()-style
     * single-extension logic would only see '.gz'/'.xz'.
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
            'architecture' => $release->architecture,
            'release_type' => $release->release_type,
            'required_base_version' => $release->required_base_version,
            'sha256' => $release->sha256,
            'disk_path' => $release->disk_path,
            'url' => $release->url(),
            'notes' => $release->notes,
            'file_size' => Storage::disk('public')->exists($release->disk_path)
                ? Storage::disk('public')->size($release->disk_path)
                : null,
            'created_at' => $release->created_at->toIso8601String(),
            'creator' => $release->creator?->only('id', 'name'),
            'channels' => $release->channels->map(fn ($c) => [
                'channel' => $c->channel,
                'tagged_at' => $c->created_at->toIso8601String(),
                'tagged_by' => $c->taggedBy?->only('id', 'name'),
            ])->all(),
        ];
    }
}
