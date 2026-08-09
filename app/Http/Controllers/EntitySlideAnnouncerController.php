<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\SlideAnnouncer;
use App\Models\SlideAnnouncerPairingCode;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EntitySlideAnnouncerController extends Controller
{
    public function index(Request $request, Entity $entity): Response
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isEntityAdmin($entity->id), 403);

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
        ]);
    }

    public function storePairingCode(Request $request, Entity $entity)
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isEntityAdmin($entity->id), 403);

        SlideAnnouncerPairingCode::create([
            'code' => $this->generateUnusedCode(),
            'entity_id' => $entity->id,
            'created_by' => $user->id,
            'expires_at' => now()->addMinutes(config('slide_announcer.pairing_code_ttl_minutes')),
        ]);

        return back();
    }

    public function update(Request $request, Entity $entity, SlideAnnouncer $slideAnnouncer)
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isEntityAdmin($entity->id), 403);
        abort_unless($slideAnnouncer->entity_id === $entity->id, 404);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'update_channel' => 'sometimes|in:stable,testing,developer',
            'auto_update_enabled' => 'sometimes|boolean',
            'settings' => 'sometimes|array',
        ]);

        $slideAnnouncer->update($data);

        return back()->with('success', 'Device updated.');
    }

    public function destroy(Request $request, Entity $entity, SlideAnnouncer $slideAnnouncer)
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isEntityAdmin($entity->id), 403);
        abort_unless($slideAnnouncer->entity_id === $entity->id, 404);

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
            'mac_address' => $device->mac_address,
            'device_uuid' => $device->device_uuid,
            'app_version' => $device->app_version,
            'os_version' => $device->os_version,
            'update_channel' => $device->update_channel,
            'auto_update_enabled' => $device->auto_update_enabled,
            'settings' => $device->settings,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'last_ip' => $device->last_ip,
            'last_cpu_temp_c' => $device->last_cpu_temp_c,
            'paired_at' => $device->paired_at?->toIso8601String(),
            'online' => $device->isOnline(),
        ];
    }
}
