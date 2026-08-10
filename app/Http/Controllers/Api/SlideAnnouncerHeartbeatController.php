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
        $appUpdateAvailable = $activeAppRelease && $activeAppRelease->version !== $device->app_version;

        $activeOsRelease = SlideAnnouncerRelease::resolveForDevice('os', $device->architecture, $device->update_channel, $device->os_version);
        $osUpdateAvailable = $activeOsRelease && $activeOsRelease->version !== $device->os_version;

        return response()->json([
            'ok' => true,
            'latest_app_version' => $activeAppRelease?->version,
            'app_update_available' => $appUpdateAvailable,
            'app_download_url' => $appUpdateAvailable ? $activeAppRelease->url() : null,
            'app_sha256' => $appUpdateAvailable ? $activeAppRelease->sha256 : null,
            'latest_os_version' => $activeOsRelease?->version,
            'os_update_available' => $osUpdateAvailable,
            'os_bundle_url' => $osUpdateAvailable ? $activeOsRelease->url() : null,
            'os_bundle_sha256' => $osUpdateAvailable ? $activeOsRelease->sha256 : null,
            'os_auto_update_enabled' => $device->auto_update_enabled,
        ]);
    }
}
