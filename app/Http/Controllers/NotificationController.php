<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Support\NotificationPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A user's own notifications: the inbox (not yet cleared), the archive
 * (cleared; purged after the retention period by notifications:prune) and
 * the bell's dropdown. Every query is scoped to the signed-in user.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $tab = $request->query('tab') === 'archive' ? 'archive' : 'inbox';
        $category = $request->query('category');

        $notifications = Notification::forUser($user)
            ->when($tab === 'archive', fn ($q) => $q->archived(), fn ($q) => $q->inbox())
            ->when(NotificationPreferences::exists((string) $category), fn ($q) => $q->where('category', $category))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $notifications->setCollection(Notification::toClientMany($notifications->getCollection()));

        return Inertia::render('Notifications/Index', [
            'tab'           => $tab,
            'category'      => $category,
            'notifications' => $notifications,
            'categories'    => array_keys(NotificationPreferences::forUser($user)),
            'inboxCount'    => Notification::forUser($user)->inbox()->count(),
        ]);
    }

    /** The bell's dropdown: newest inbox items plus the unread count. */
    public function recent(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'notifications' => Notification::toClientMany(
                Notification::forUser($user)->inbox()->latest()
                    ->limit(config('notifications.recent_limit'))->get()
            ),
            'unread' => $user->unreadNotificationCount(),
        ]);
    }

    /** Follow a notification's link, marking it read on the way. */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $n = $this->find($request, $notification);
        $n->markAsRead();

        $url = $n->data['url'] ?? null;

        // Only ever redirect within this site.
        return $url && str_starts_with($url, '/') && ! str_starts_with($url, '//')
            ? redirect($url)
            : redirect()->route('notifications.index');
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $this->find($request, $notification)->markAsRead();

        return back();
    }

    public function archive(Request $request, string $notification): RedirectResponse
    {
        $this->find($request, $notification)->archive();

        return back();
    }

    public function restore(Request $request, string $notification): RedirectResponse
    {
        $this->find($request, $notification)->restore();

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        Notification::forUser($request->user())->inbox()->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back();
    }

    /** "Clear all": archives the whole inbox (optionally one category). */
    public function archiveAll(Request $request): RedirectResponse
    {
        $category = $request->input('category');
        $now = now();
        $base = Notification::forUser($request->user())->inbox()
            ->when(NotificationPreferences::exists((string) $category), fn ($q) => $q->where('category', $category));

        (clone $base)->whereNull('read_at')->update(['read_at' => $now]);
        $base->update(['archived_at' => $now]);

        return back();
    }

    private function find(Request $request, string $id): Notification
    {
        return Notification::forUser($request->user())->whereKey($id)->firstOrFail();
    }
}
