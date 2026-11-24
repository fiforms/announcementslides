<?php

namespace App\Notifications;

use App\Models\Slide;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Support\Collection;

/**
 * Notifications about the slide review workflow: a submitter hears their
 * slide was approved or rejected; site admins hear about new submissions.
 */
class SlideNotifications
{
    /** Tell the uploader their pending slide was approved (published) or rejected. */
    public static function reviewed(Slide $slide, ?User $reviewer = null): void
    {
        if (! in_array($slide->status, ['published', 'rejected'], true) || ! $slide->uploaded_by) {
            return;
        }
        // Reviewing your own slide needs no notice.
        if ($reviewer && $reviewer->id === $slide->uploaded_by) {
            return;
        }

        $approved = $slide->status === 'published';
        $uploader = $slide->uploader;

        Notifier::send(
            'slide_review',
            $approved ? 'slide_approved' : 'slide_rejected',
            [$slide->uploaded_by],
            [
                'params' => [
                    'slide'    => $slide->title,
                    'reviewer' => $reviewer?->name,
                ],
                'url' => $approved
                    ? route('slides.show', $slide->token, absolute: false)
                    : ($uploader?->isContributor()
                        ? route('my-slides.index', absolute: false)
                        : route('slides.submit', absolute: false)),
                'slide_id' => $slide->id,
            ],
        );
    }

    /**
     * Tell site admins that slides are waiting for review. One notice per
     * submission, however many files it held.
     *
     * @param  Collection<int, Slide>|array<Slide>  $slides
     */
    public static function submitted(Collection|array $slides): void
    {
        $slides = collect($slides)->filter(fn (Slide $s) => $s->status === 'pending')->values();
        $first = $slides->first();
        if (! $first) {
            return;
        }

        $admins = User::where('role', 'admin')
            ->when($first->uploaded_by, fn ($q) => $q->where('id', '!=', $first->uploaded_by))
            ->pluck('id');

        Notifier::send('admin_review_queue', 'slide_pending', $admins, [
            'params' => [
                'slide' => $first->title,
                'count' => $slides->count(),
                'user'  => $first->uploader?->name,
            ],
            'url'       => route('admin.slides.index', absolute: false),
            'slide_id'  => $first->id,
        ]);
    }
}
