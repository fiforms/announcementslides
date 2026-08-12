<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SlideAnnouncer;
use Inertia\Inertia;
use Inertia\Response;

class SlideAnnouncerConsoleController extends Controller
{
    /**
     * Cross-entity fleet view — see SLIDE_ANNOUNCER.md's "Admin/entity-leader
     * visibility," previously just a sketch ("not built yet"). Guarded
     * entirely by the admin/ route group's EnsureAdmin middleware, same as
     * EntityConsoleController — no per-method check needed here.
     *
     * Deliberately doesn't duplicate a device-detail page: each row links
     * straight into entity.slide-announcers.show, which already grants a
     * platform admin access via its own `$user->isAdmin() || ...` guard.
     */
    public function index(): Response
    {
        $devices = SlideAnnouncer::with('entity')
            ->whereNull('revoked_at')
            ->orderBy('name')
            ->get()
            ->map(fn (SlideAnnouncer $device) => [
                'id' => $device->id,
                'name' => $device->name,
                'entity' => $device->entity ? ['id' => $device->entity->id, 'name' => $device->entity->name] : null,
                'app_version' => $device->app_version,
                'os_version' => $device->os_version,
                'architecture' => $device->architecture,
                'update_channel' => $device->update_channel,
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'last_ip' => $device->last_ip,
                'last_cpu_temp_c' => $device->last_cpu_temp_c,
                'online' => $device->isOnline(),
            ]);

        return Inertia::render('Admin/SlideAnnouncers', [
            'devices' => $devices,
        ]);
    }
}
