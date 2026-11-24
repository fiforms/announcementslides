<?php

namespace App\Notifications;

use App\Models\Entity;
use App\Models\GlobalShowTemplate;
use App\Models\Show;
use App\Models\Slide;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "A new slide is available" notices for the people who run churches'
 * shows (leaders and editors):
 *
 *  - a global slide is published (category global_slide),
 *  - a nearby church shares a slide (nearby_slide),
 *  - a promo show, or a new slide in one, is published (promo).
 *
 * Each recipient gets one notice per slide listing, for each church they
 * manage, which shows it was automatically added to (or that it wasn't).
 * A slide is announced once (`slides.announced_at`, claimed atomically), so
 * re-running the show fan-out never repeats it. These run from queued jobs:
 * the recipient list can be every manager in the conference.
 */
class SlideAvailabilityNotifications
{
    /** Churches listed per notice; the rest are counted. */
    private const MAX_PLACEMENTS = 10;

    /** Promo slides published this soon after the promo itself are part of its first batch. */
    private const PROMO_BATCH_MINUTES = 10;

    /**
     * @param  array<int>  $attachedShowIds  shows the fan-out just added it to
     */
    public static function announce(Slide $slide, array $attachedShowIds = []): void
    {
        $slide->refresh();

        if ($slide->status !== 'published' || $slide->announced_at) {
            return;
        }

        $isGlobal = $slide->entity_id === null;
        if (! $isGlobal && ! $slide->share_nearby) {
            return; // A church's own slide: nothing to tell anyone else.
        }

        if ($isGlobal && ($template = self::promoFor($slide))) {
            self::announcePromo($template, $slide);

            return;
        }

        if (! self::claim($slide)) {
            return;
        }

        $category = $isGlobal ? 'global_slide' : 'nearby_slide';
        $managers = self::managersByEntity(Show::candidateEntityIdsForSlide($slide));
        unset($managers[$slide->uploaded_by]);

        $shows = Show::whereIn('id', $attachedShowIds)->orderBy('name')->get(['id', 'entity_id', 'name'])
            ->groupBy('entity_id');

        self::send($category, $category, $slide, $managers, $shows, [
            'slide'  => $slide->title,
            'entity' => $isGlobal ? null : $slide->entity?->name,
        ]);

        self::tellAdmins($slide);
    }

    /** A promo show (one-off show distributed to every church) got a published slide. */
    public static function announcePromo(GlobalShowTemplate $template, Slide $slide): void
    {
        $slide->refresh();

        if ($slide->status !== 'published' || ! self::claim($slide)) {
            return;
        }

        $newPromo = GlobalShowTemplate::whereKey($template->id)->whereNull('announced_at')
            ->update(['announced_at' => now()]) === 1;

        if (! $newPromo && $template->fresh()->announced_at?->gt(now()->subMinutes(self::PROMO_BATCH_MINUTES))) {
            return; // Uploaded together with the promo: its first notice covers it.
        }

        $shows = Show::where('global_template_id', $template->id)->get(['id', 'entity_id', 'name'])
            ->groupBy('entity_id');
        $managers = self::managersByEntity($shows->keys());
        unset($managers[$slide->uploaded_by]);

        self::send('promo', $newPromo ? 'promo_available' : 'promo_slide_added', $slide, $managers, $shows, [
            'slide' => $slide->title,
            'promo' => $template->name,
        ]);
    }

    /**
     * @param  array<int, array<int>>  $managers  user id => entity ids they manage
     * @param  Collection<int, Collection<int, Show>>  $showsByEntity  shows it landed in, by entity
     */
    private static function send(string $category, string $type, Slide $slide, array $managers, Collection $showsByEntity, array $params): void
    {
        if (! $managers) {
            return;
        }

        $names = Entity::whereIn('id', collect($managers)->flatten()->unique())->pluck('name', 'id');
        $startsAt = $slide->publish_at?->isFuture() ? $slide->publish_at->toDateString() : null;

        Notifier::send($category, $type, array_keys($managers), function (User $user) use ($managers, $names, $showsByEntity, $slide, $params, $startsAt) {
            $entityIds = collect($managers[$user->id])->unique()
                ->sortBy(fn ($id) => mb_strtolower($names[$id] ?? ''))->values();

            $placements = $entityIds->take(self::MAX_PLACEMENTS)->map(fn ($id) => [
                'entity' => $names[$id] ?? '?',
                'shows'  => ($showsByEntity[$id] ?? collect())->pluck('name')->all(),
            ])->all();

            // Link to the first church's show the slide landed in, else its shows page.
            $first = $entityIds->first();
            $show = ($showsByEntity[$first] ?? collect())->first();

            return [
                'params' => $params + [
                    'placements' => $placements,
                    'more'       => max(0, $entityIds->count() - self::MAX_PLACEMENTS),
                    'starts_at'  => $startsAt,
                ],
                'url'      => route('shows.index', array_filter(['entity_id' => $first, 'show_id' => $show?->id]), absolute: false),
                'slide_id' => $slide->id,
            ];
        });
    }

    /** Site admins hear about slides that reached churches without passing their review. */
    private static function tellAdmins(Slide $slide): void
    {
        $uploader = $slide->uploader;

        if ($slide->entity_id !== null) {
            $type = 'admin_slide_shared';
        } elseif ($uploader && ! $uploader->isAdmin() && ! $slide->reviewed_by) {
            $type = 'admin_global_published';
        } else {
            return;
        }

        $admins = User::where('role', 'admin')->where('id', '!=', $slide->uploaded_by ?? 0)->pluck('id');

        Notifier::send('admin_shared', $type, $admins, [
            'params'   => ['slide' => $slide->title, 'entity' => $slide->entity?->name, 'user' => $uploader?->name],
            'url'      => route('admin.slides.edit', $slide, absolute: false),
            'slide_id' => $slide->id,
        ]);
    }

    /**
     * Leaders and editors of the given (active) entities.
     *
     * @return array<int, array<int>> user id => the given entities they manage
     */
    public static function managersByEntity(iterable $entityIds): array
    {
        $map = [];

        foreach (collect($entityIds)->unique()->chunk(1000) as $chunk) {
            DB::table('user_entities')
                ->join('entities', 'entities.id', '=', 'user_entities.entity_id')
                ->whereIn('user_entities.entity_id', $chunk->all())
                ->whereIn('user_entities.role', User::MANAGER_ENTITY_ROLES)
                ->where('entities.deactivated', false)
                ->get(['user_entities.user_id', 'user_entities.entity_id'])
                ->each(function ($row) use (&$map) {
                    $map[$row->user_id][] = $row->entity_id;
                });
        }

        return $map;
    }

    private static function promoFor(Slide $slide): ?GlobalShowTemplate
    {
        $templateId = Show::whereNotNull('global_template_id')
            ->whereHas('slides', fn ($q) => $q->where('slides.id', $slide->id))
            ->value('global_template_id');

        return $templateId ? GlobalShowTemplate::find($templateId) : null;
    }

    /** Mark the slide announced; false if someone else already did. */
    private static function claim(Slide $slide): bool
    {
        return Slide::whereKey($slide->id)->whereNull('announced_at')->update(['announced_at' => now()]) === 1;
    }
}
