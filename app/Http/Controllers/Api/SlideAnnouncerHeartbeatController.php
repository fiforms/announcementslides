<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SlideAnnouncerHeartbeat;
use App\Models\SlideAnnouncerOsRelease;
use Illuminate\Http\Request;

class SlideAnnouncerHeartbeatController extends Controller
{
    /**
     * Updates the device's fleet-inventory snapshot (slide_announcers) and
     * appends a row to the rolling log (slide_announcer_heartbeats), then
     * folds both the local-app and OS update checks into the one response
     * — see SLIDE_ANNOUNCER.md, "Heartbeat + version checks."
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'app_version' => 'nullable|string|max:255',
            'os_version' => 'nullable|string|max:255',
            'cpu_temp_c' => 'nullable|numeric',
        ]);

        $device = $request->user();
        $ip = $request->ip();

        $device->update([
            'app_version' => $data['app_version'] ?? $device->app_version,
            'os_version' => $data['os_version'] ?? $device->os_version,
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

        $latestAppVersion = config('slide_announcer.app_version');
        $appUpdateAvailable = $latestAppVersion && $latestAppVersion !== $device->app_version;

        $activeOsRelease = SlideAnnouncerOsRelease::activeOnChannel($device->update_channel)->first();
        $osUpdateAvailable = $activeOsRelease && $activeOsRelease->version !== $device->os_version;

        return response()->json([
            'ok' => true,
            'latest_app_version' => $latestAppVersion,
            'app_update_available' => $appUpdateAvailable,
            'app_download_url' => $appUpdateAvailable ? config('slide_announcer.app_download_url') : null,
            'latest_os_version' => $activeOsRelease?->version,
            'os_update_available' => $osUpdateAvailable,
            'os_bundle_url' => $osUpdateAvailable ? $activeOsRelease->bundleUrl() : null,
            'os_bundle_sha256' => $osUpdateAvailable ? $activeOsRelease->sha256 : null,
            'os_auto_update_enabled' => $device->auto_update_enabled,
        ]);
    }
}
