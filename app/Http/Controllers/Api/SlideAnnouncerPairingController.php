<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Models\SlideAnnouncer;
use App\Models\SlideAnnouncerPairingCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SlideAnnouncerPairingController extends Controller
{
    /**
     * Public, unauthenticated (throttled at the route). Handles both the
     * initial pairing of a fresh device and re-pairing an already-paired
     * one — there's no separate "transfer" endpoint, see SLIDE_ANNOUNCER.md
     * "Pairing flow." A generic error is returned for any bad/expired code
     * so we don't leak which codes exist.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|size:6',
            'device_name' => 'required|string|max:255',
            'mac_address' => 'nullable|string|max:255',
            'device_uuid' => 'nullable|string|max:255',
            // Boot-yaml language hint (see slideannouncer's pairing.py) —
            // only ever used to seed language_id when it isn't already set,
            // never to override an entity admin's explicit choice.
            'language' => 'nullable|string|max:10',
        ]);

        // Backoff-style hit counter on top of the route's throttle:10,1 —
        // a bare rate limit alone doesn't slow down a slow, patient guesser.
        $backoffKey = 'slide-announcer-pair-backoff:'.$request->ip();
        $hits = Cache::get($backoffKey, 0);
        if ($hits > 20) {
            abort(429);
        }
        Cache::put($backoffKey, $hits + 1, now()->addMinutes(10));

        $pairingCode = SlideAnnouncerPairingCode::unused()->unexpired()
            ->where('code', $data['code'])
            ->first();

        if (! $pairingCode) {
            abort(422, 'Invalid or expired pairing code.');
        }

        $hintedLanguageId = ! empty($data['language'])
            ? Language::where('abbreviation', $data['language'])->value('id')
            : null;

        [$device, $token, $siblingHostnames] = DB::transaction(function () use ($pairingCode, $data, $hintedLanguageId) {
            // Re-pairing: a device_uuid already on file means this is the
            // same physical device moving sites (or re-pairing after an
            // unpair/revoke), not a new fleet entry — see SLIDE_ANNOUNCER.md
            // "Pairing flow." Old tokens are revoked so a stale one from a
            // previous site can't keep working.
            $device = ! empty($data['device_uuid'])
                ? SlideAnnouncer::where('device_uuid', $data['device_uuid'])->first()
                : null;

            if ($device) {
                $device->tokens()->delete();
                $device->update([
                    'entity_id' => $pairingCode->entity_id,
                    'name' => $data['device_name'],
                    'mac_address' => $data['mac_address'] ?? $device->mac_address,
                    'last_ip' => request()->ip(),
                    'paired_at' => now(),
                    'paired_by' => $pairingCode->created_by,
                    'revoked_at' => null,
                    // Only seed from the device's boot-yaml hint if no one
                    // has already assigned this device a language — an
                    // entity admin's explicit choice always wins.
                    'language_id' => $device->language_id ?? $hintedLanguageId,
                ]);
            } else {
                $device = SlideAnnouncer::create([
                    'entity_id' => $pairingCode->entity_id,
                    'name' => $data['device_name'],
                    'mac_address' => $data['mac_address'] ?? null,
                    'device_uuid' => $data['device_uuid'] ?? null,
                    'last_ip' => request()->ip(),
                    'paired_at' => now(),
                    'paired_by' => $pairingCode->created_by,
                    'language_id' => $hintedLanguageId,
                ]);
            }

            $pairingCode->update([
                'used_at' => now(),
                'slide_announcer_id' => $device->id,
            ]);

            $token = $device->createToken('slide-announcer', ['slide-announcer']);

            // Other devices already on file for this entity, so the
            // pairing device can pick a hostname that doesn't collide with
            // a sibling on the same local network — see slideannouncer's
            // pairing.py, which derives its hostname from the typed device
            // name and appends a numeric suffix against this list until it
            // finds one that's free. Each device's `hostname` here is
            // whatever it last reported on a heartbeat (SlideAnnouncerHeartbeatController),
            // so a device that has never heartbeated yet simply isn't
            // checked against — an acceptable gap for a same-boot double
            // pairing, not worth an extra live-network probe to close.
            $siblingHostnames = SlideAnnouncer::where('entity_id', $device->entity_id)
                ->whereKeyNot($device->id)
                ->whereNotNull('hostname')
                ->pluck('hostname');

            return [$device, $token, $siblingHostnames];
        });

        return response()->json([
            'slide_announcer_id' => $device->id,
            'entity_id' => $device->entity_id,
            // So the pairing screen can show which church/school this
            // device just joined immediately, without waiting on the first
            // heartbeat — see heartbeat's own entity_name for how a later
            // move to a different entity (re-pair) keeps this in sync.
            'entity_name' => $device->entity->name,
            'token' => $token->plainTextToken,
            'sibling_hostnames' => $siblingHostnames,
        ], 201);
    }
}
