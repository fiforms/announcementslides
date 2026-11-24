<?php

namespace App\Console\Commands;

use App\Models\Slide;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * "Started showing" and "expired" notices (category slide_schedule) for a
 * slide's uploader and, for a church's slide, that church's leaders and
 * editors. Publishing and expiry are worked out at query time, so this
 * sweep notices the moment passing; `start_notified_at` /
 * `expiry_notified_at` keep each from repeating.
 *
 *  - "Started" only applies to a slide scheduled ahead (publish_at later
 *    than when it was created).
 *  - An expiry that is really a manual archive (expires_at set to "now",
 *    so it matches updated_at) is marked but not announced: whoever
 *    archived it knows.
 *  - Times that passed more than LOOKBACK_HOURS ago (the scheduler was
 *    down) are marked without a notice.
 */
class NotificationsSlideSchedule extends Command
{
    protected $signature = 'notifications:slide-schedule';

    protected $description = 'Notify about slides that started showing or expired';

    private const LOOKBACK_HOURS = 48;

    public function handle(): int
    {
        $now = now();
        $started = 0;
        $expired = 0;

        Slide::query()
            ->where('status', 'published')
            ->whereNull('start_notified_at')
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', $now)
            ->with('entity:id,name')
            ->chunkById(200, function ($slides) use ($now, &$started) {
                foreach ($slides as $slide) {
                    $slide->forceFill(['start_notified_at' => $now])->saveQuietly();

                    $scheduledAhead = $slide->publish_at->gt($slide->created_at);
                    $recent = $slide->publish_at->gt($now->copy()->subHours(self::LOOKBACK_HOURS));
                    $stillShowing = ! $slide->expires_at || $slide->expires_at->isFuture();

                    if ($scheduledAhead && $recent && $stillShowing) {
                        $started += $this->notify($slide, 'slide_started');
                    }
                }
            });

        Slide::query()
            ->where('status', 'published')
            ->whereNull('expiry_notified_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->with('entity:id,name')
            ->chunkById(200, function ($slides) use ($now, &$expired) {
                foreach ($slides as $slide) {
                    $manualArchive = abs($slide->expires_at->diffInSeconds($slide->updated_at)) < 60;
                    $recent = $slide->expires_at->gt($now->copy()->subHours(self::LOOKBACK_HOURS));

                    $slide->forceFill(['expiry_notified_at' => $now])->saveQuietly();

                    if (! $manualArchive && $recent) {
                        $expired += $this->notify($slide, 'slide_expired');
                    }
                }
            });

        $this->info("Sent $started started and $expired expired notice(s).");

        return self::SUCCESS;
    }

    private function notify(Slide $slide, string $type): int
    {
        $managers = $slide->entity_id
            ? DB::table('user_entities')->where('entity_id', $slide->entity_id)
                ->whereIn('role', User::MANAGER_ENTITY_ROLES)->pluck('user_id')
            : collect();

        $recipients = $managers->push($slide->uploaded_by)->filter()->unique();

        return Notifier::send('slide_schedule', $type, $recipients, fn (User $user) => [
            'params'   => ['slide' => $slide->title, 'entity' => $slide->entity?->name],
            'url'      => $this->linkFor($slide, $user, $managers->contains($user->id)),
            'slide_id' => $slide->id,
        ]);
    }

    private function linkFor(Slide $slide, User $user, bool $manager): string
    {
        if ($manager) {
            return route('shows.index', ['entity_id' => $slide->entity_id], absolute: false);
        }

        return match (true) {
            $user->isAdmin()       => route('admin.slides.edit', $slide, absolute: false),
            $user->isContributor() => route('my-slides.index', absolute: false),
            default                => route('slides.show', $slide->token, absolute: false),
        };
    }
}
