<?php

namespace App\Notifications;

use App\Models\Entity;
use App\Models\User;
use App\Services\Notifier;

/**
 * Notifications about people joining: an invitation accepted, or an editor
 * added to a church's team.
 */
class TeamNotifications
{
    /**
     * Someone became an editor of an entity (an invitation redeemed, or a
     * leader adding an existing account). The person who invited/added them
     * and the entity's leaders hear about it; the new editor hears they were
     * added when it wasn't their own doing (an existing account).
     */
    public static function editorJoined(User $editor, Entity|int $entity, ?int $byUserId, bool $tellEditor = false): void
    {
        $entity = $entity instanceof Entity ? $entity : Entity::find($entity);
        if (! $entity) {
            return;
        }

        $leaders = $entity->users()->wherePivot('role', 'admin')->pluck('users.id');
        $recipients = $leaders->push($byUserId)->filter()
            ->reject(fn ($id) => $id === $editor->id);

        $by = $byUserId ? User::find($byUserId) : null;

        Notifier::send('team', 'editor_joined', $recipients, fn (User $u) => [
            'params' => [
                'user'   => $editor->name,
                'entity' => $entity->name,
                // "you invited" vs "invited by X" is decided by the frontend.
                'by'     => $u->id === $byUserId ? null : $by?->name,
                'by_you' => $u->id === $byUserId,
            ],
            'url' => route('entity.people.index', $entity, absolute: false),
        ]);

        if ($tellEditor) {
            Notifier::send('team', 'added_as_editor', [$editor], [
                'params' => ['entity' => $entity->name, 'by' => $by?->name],
                'url'    => route('shows.index', ['entity_id' => $entity->id], absolute: false),
            ]);
        }
    }

    /** A site admin's invitation (UserInvitation) was used to create an account. */
    public static function userInvitationAccepted(User $user, ?int $invitedBy): void
    {
        if (! $invitedBy) {
            return;
        }

        Notifier::send('team', 'user_joined', [$invitedBy], [
            'params' => ['user' => $user->name, 'email' => $user->email, 'role' => $user->role],
            'url'    => route('admin.users.index', absolute: false),
        ]);
    }
}
