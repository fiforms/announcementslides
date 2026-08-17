<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Slide;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $stats = [
            'live'          => Slide::current()->count(),
            'pending'       => Slide::pendingReview()->count(),
            'upcoming'      => Slide::upcoming()->count(),
            'archived'      => Slide::archived()->count(),
            'expiring_soon' => Slide::current()
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now()->addDays(7))
                ->count(),
        ];

        $pendingSlides = Slide::pendingReview()
            ->with(['uploader:id,name', 'primaryMedia'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($s) => [
                'id'            => $s->id,
                'title'         => $s->title,
                'thumbnail_url' => $s->thumbnail_url,
                'file_url'      => $s->file_url,
                'mime_type'     => $s->mime_type,
                'uploader'      => $s->uploader?->only('id', 'name'),
                'created_at'    => $s->created_at->diffForHumans(),
            ]);

        $expiringSoon = Slide::current()
            ->with('primaryMedia')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(7))
            ->orderBy('expires_at')
            ->limit(5)
            ->get()
            ->map(fn ($s) => [
                'id'            => $s->id,
                'title'         => $s->title,
                'thumbnail_url' => $s->thumbnail_url,
                'file_url'      => $s->file_url,
                'mime_type'     => $s->mime_type,
                'expires_at'    => $s->expires_at->toIso8601String(),
                'expires_human' => $s->expires_at->diffForHumans(),
            ]);

        $recentlyAdded = Slide::where('status', 'published')
            ->with(['uploader:id,name', 'primaryMedia'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($s) => [
                'id'            => $s->id,
                'title'         => $s->title,
                'status'        => $s->status,
                'thumbnail_url' => $s->thumbnail_url,
                'file_url'      => $s->file_url,
                'mime_type'     => $s->mime_type,
                'uploader'      => $s->uploader?->only('id', 'name'),
                'created_at'    => $s->created_at->diffForHumans(),
            ]);

        return Inertia::render('Admin/Dashboard', compact(
            'stats',
            'pendingSlides',
            'expiringSoon',
            'recentlyAdded',
        ));
    }
}
