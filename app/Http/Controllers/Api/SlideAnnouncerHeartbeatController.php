<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SlideAnnouncerHeartbeat;
use App\Models\SlideAnnouncerRelease;
use Illuminate\Http\Request;

class SlideAnnouncerHeartbeatController extends Controller
{
    /**
     * Updates the device's fleet-inventory snapshot (slide_announcers) and
     * appends a row to the rolling log (slide_announcer_heartbeats), then
     * folds both the local-app and OS update checks into the one response
     * — see SLIDE_ANNOUNCER.md, "Heartbeat + version checks." Both checks
     * look up the release currently *tagged* with this device's
     * update_channel, matching its reported architecture — a device with
     * no architecture reported yet (first-ever heartbeat) simply matches
     * nothing, same as any other "no release currently offered" case.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'app_version' => 'nullable|string|max:255',
            'os_version' => 'nullable|string|max:255',
            'architecture' => 'nullable|string|max:64',
            'cpu_temp_c' => 'nullable|numeric',
        ]);

        $device = $request->user();
        $ip = $request->ip();

        $device->update([
            'app_version' => $data['app_version'] ?? $device->app_version,
            'os_version' => $data['os_version'] ?? $device->os_version,
            'architecture' => $data['architecture'] ?? $device->architecture,
            'last_ip' => $ip,
            'last_cpu_temp_c' => $data['cpu_temp_c'] ?? $device->last_cpu_temp_c,
            'last_seen_at' => now(),
        ]);

        SlideAnnouncerHeartbeat::create([
            'slide_announcer_id' => $device->id,
            'app_version' => $data['app_version'] ?? null,
            'os_version' => $data['os_version'] ?? null,
            'ip_address' => $ip,
            'cpu_temp_c' => $data['cpu_temp_c'] ?? null,
        ]);

        $activeAppRelease = SlideAnnouncerRelease::resolveForDevice('app', $device->architecture, $device->update_channel, $device->app_version);
        $appUpdateAvailable = $activeAppRelease && static::releaseIsNewer($activeAppRelease, $device->app_version);

        $activeOsRelease = SlideAnnouncerRelease::resolveForDevice('os', $device->architecture, $device->update_channel, $device->os_version);
        $osUpdateAvailable = $activeOsRelease && static::releaseIsNewer($activeOsRelease, $device->os_version);

        return response()->json([
            'ok' => true,
            // Server is authoritative for the device's name — an entity
            // admin can rename it from the fleet UI (EntitySlideAnnouncerController::update),
            // and this is how that rename reaches the device itself, since
            // there's no separate push channel: local-app/backend/heartbeat.py
            // folds this into its local device-name cache on every heartbeat.
            'device_name' => $device->name,
            // Same rationale as device_name above — also covers a device
            // moving to a different entity via re-pair, which changes
            // entity_id without touching device_name at all.
            'entity_name' => $device->entity->name,
            // Null until an entity admin assigns one (EntitySlideAnnouncerController::update),
            // in which case the device keeps using its own boot-yaml
            // default — see local-app/backend/pairing.py's
            // read_effective_language() and slideannouncer/LOCALIZATION_TODO.md.
            'language' => $device->language?->abbreviation,
            'latest_app_version' => $activeAppRelease?->version,
            'app_update_available' => $appUpdateAvailable,
            'app_download_url' => $appUpdateAvailable ? $activeAppRelease->url() : null,
            'app_sha256' => $appUpdateAvailable ? $activeAppRelease->sha256 : null,
            'latest_os_version' => $activeOsRelease?->version,
            'os_update_available' => $osUpdateAvailable,
            'os_bundle_url' => $osUpdateAvailable ? $activeOsRelease->url() : null,
            'os_bundle_sha256' => $osUpdateAvailable ? $activeOsRelease->sha256 : null,
            // Lets the device tell a hotfix (live-rootfs write, no reboot
            // needed — see make-hotfix-bundle.sh) apart from a full image
            // (needs the install -> tryboot -> health-check -> commit
            // dance) without guessing from the URL/filename.
            'os_release_type' => $osUpdateAvailable ? $activeOsRelease->release_type : null,
            'os_auto_update_enabled' => $device->auto_update_enabled,
        ]);
    }

    /**
     * A hotfix's version is only ever compared for "is this different from
     * what the device is on" — resolveForDevice() already guarantees a
     * hotfix's required_base_version exactly equals the device's current
     * version before it's even considered, so there's no forward/backward
     * ambiguity to resolve here (a hotfix can't apply to a device that
     * isn't already on its exact prerequisite version).
     *
     * A 'full' release is different: it's just "whatever this channel has
     * tagged," with nothing structurally preventing an admin from tagging
     * one whose version is lower than (or equal to) what some devices on
     * that channel already run — which, without this check, would report
     * an "update" that's actually a downgrade or a no-op re-install. Only
     * offer it when it's a genuine forward version move (or the device has
     * never reported a version at all yet).
     */
    protected static function releaseIsNewer(SlideAnnouncerRelease $release, ?string $currentVersion): bool
    {
        if ($release->release_type === 'hotfix') {
            return $release->version !== $currentVersion;
        }

        return $currentVersion === null || version_compare($release->version, $currentVersion, '>');
    }
}
