<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesEntityAccess;
use App\Models\Entity;
use App\Models\Language;
use App\Models\SlideAnnouncer;
use App\Models\SlideAnnouncerHeartbeat;
use App\Models\SlideAnnouncerPairingCode;
use App\Models\SlideAnnouncerRelease;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class EntitySlideAnnouncerController extends Controller
{
    use AuthorizesEntityAccess;

    public function index(Request $request): Response
    {
        $entity = Entity::findOrFail($this->authorizedEntityId($request));

        $devices = $entity->slideAnnouncers()
            ->whereNull('revoked_at')
            ->orderBy('name')
            ->get()
            ->map(fn (SlideAnnouncer $device) => $this->deviceResource($device));

        $pairingCode = SlideAnnouncerPairingCode::where('entity_id', $entity->id)
            ->unused()->unexpired()
            ->latest()
            ->first();

        return Inertia::render('Entity/SlideAnnouncers', [
            'entity' => ['id' => $entity->id, 'name' => $entity->name],
            'devices' => $devices,
            'pairingCode' => $pairingCode ? [
                'code' => $pairingCode->code,
                'expires_at' => $pairingCode->expires_at->toIso8601String(),
            ] : null,
            'diskImages' => $this->diskImages(),
            'languages' => Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']),
        ]);
    }

    /**
     * Current os/disk_image releases tagged stable or testing, grouped by
     * architecture, so an entity leader can grab an SD-card image to
     * provision a new device without needing admin access — the same
     * releases platform admins publish from Admin/SlideAnnouncerReleases,
     * just filtered to the two channels an entity actually points devices
     * at (developer is admin/tester-only, so it's excluded here).
     */
    private function diskImages(): array
    {
        return SlideAnnouncerRelease::query()
            ->where('kind', 'os')
            ->where('release_type', 'disk_image')
            ->with('channels')
            ->get()
            ->filter(fn (SlideAnnouncerRelease $release) => $release->channels
                ->whereIn('channel', ['stable', 'testing'])
                ->isNotEmpty())
            ->groupBy('architecture')
            ->sortKeys()
            ->map(function ($releases, string $architecture) {
                $taggedOn = fn (string $channel) => $releases->first(
                    fn (SlideAnnouncerRelease $r) => $r->channels->contains('channel', $channel)
                );

                return [
                    'architecture' => $architecture,
                    'stable' => optional($taggedOn('stable'), fn ($r) => $this->diskImageResource($r)),
                    'testing' => optional($taggedOn('testing'), fn ($r) => $this->diskImageResource($r)),
                ];
            })
            ->values()
            ->all();
    }

    private function diskImageResource(SlideAnnouncerRelease $release): array
    {
        return [
            'version' => $release->version,
            'url' => $release->url(),
            'sha256' => $release->sha256,
            'file_size' => Storage::disk('public')->exists($release->disk_path)
                ? Storage::disk('public')->size($release->disk_path)
                : null,
            'created_at' => $release->created_at->toIso8601String(),
        ];
    }

    /**
     * Single-device detail: everything deviceResource() already exposes,
     * plus its recent heartbeat log (never surfaced anywhere before this —
     * SlideAnnouncerHeartbeat rows were written on every check-in but only
     * ever read by the pruning command). Same guard as every other action
     * here; also reachable by a platform admin via
     * Admin/SlideAnnouncerConsoleController's fleet list linking straight
     * into this route rather than duplicating a detail page.
     */
    public function show(Request $request, SlideAnnouncer $slideAnnouncer): Response
    {
        $user = $request->user();
        $entity = $slideAnnouncer->entity;
        abort_unless($user->isAdmin() || $user->isEntityAdmin($entity->id), 403);

        $heartbeats = $slideAnnouncer->heartbeats()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (SlideAnnouncerHeartbeat $heartbeat) => [
                'app_version' => $heartbeat->app_version,
                'os_version' => $heartbeat->os_version,
                'ip_address' => $heartbeat->ip_address,
                'cpu_temp_c' => $heartbeat->cpu_temp_c,
                'created_at' => $heartbeat->created_at->toIso8601String(),
            ]);

        return Inertia::render('Entity/SlideAnnouncerShow', [
            'entity' => ['id' => $entity->id, 'name' => $entity->name],
            'device' => $this->deviceResource($slideAnnouncer),
            'heartbeats' => $heartbeats,
            'languages' => Language::orderBy('name')->get(['id', 'abbreviation', 'name', 'native_name']),
        ]);
    }

    public function storePairingCode(Request $request)
    {
        $entityId = $this->authorizedEntityId($request);

        SlideAnnouncerPairingCode::create([
            'code' => $this->generateUnusedCode(),
            'entity_id' => $entityId,
            'created_by' => $request->user()->id,
            'expires_at' => now()->addMinutes(config('slide_announcer.pairing_code_ttl_minutes')),
        ]);

        return back();
    }

    public function update(Request $request, SlideAnnouncer $slideAnnouncer)
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isEntityAdmin($slideAnnouncer->entity_id), 403);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'language_id' => 'sometimes|nullable|exists:languages,id',
            'update_channel' => 'sometimes|in:stable,testing,developer',
            'auto_update_enabled' => 'sometimes|boolean',
            // Fleet-wide force-disable for SRT Sink — the passphrase itself
            // is never admin-settable here, only device-reported (see
            // SlideAnnouncerHeartbeatController::store()).
            'srt_sink_enabled' => 'sometimes|boolean',
            'settings' => 'sometimes|array',
        ]);

        $slideAnnouncer->update($data);

        return back()->with('success', 'Device updated.');
    }

    public function destroy(Request $request, SlideAnnouncer $slideAnnouncer)
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isEntityAdmin($slideAnnouncer->entity_id), 403);

        // Revoke, don't hard-delete — keeps history for the "needs
        // attention" UI, matching how Slide already soft-deletes. See
        // SLIDE_ANNOUNCER.md's Heartbeat/revocation handling for how a
        // revoked device is detected on its next API call.
        $slideAnnouncer->tokens()->delete();
        $slideAnnouncer->update(['revoked_at' => now()]);

        return back()->with('success', 'Device unpaired.');
    }

    private function generateUnusedCode(): string
    {
        do {
            $code = (string) random_int(100000, 999999);
        } while (SlideAnnouncerPairingCode::where('code', $code)->unused()->unexpired()->exists());

        return $code;
    }

    private function deviceResource(SlideAnnouncer $device): array
    {
        return [
            'id' => $device->id,
            'name' => $device->name,
            'language_id' => $device->language_id,
            'mac_address' => $device->mac_address,
            'device_uuid' => $device->device_uuid,
            'app_version' => $device->app_version,
            'os_version' => $device->os_version,
            'architecture' => $device->architecture,
            'update_channel' => $device->update_channel,
            'auto_update_enabled' => $device->auto_update_enabled,
            'srt_sink_enabled' => $device->srt_sink_enabled,
            'srt_sink_passphrase' => $device->srt_sink_passphrase,
            'hostname' => $device->hostname,
            'settings' => $device->settings,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'last_ip' => $device->last_ip,
            'last_cpu_temp_c' => $device->last_cpu_temp_c,
            'paired_at' => $device->paired_at?->toIso8601String(),
            'online' => $device->isOnline(),
        ];
    }
}
