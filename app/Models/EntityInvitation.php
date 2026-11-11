<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A leader's pending invitation of someone without an account to be an editor
 * of one entity. Redeemed by {@see self::redeemFor()} when the user is created.
 */
class EntityInvitation extends Model
{
    protected $fillable = ['email', 'entity_id', 'role', 'invited_by'];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** Grant every pending invitation for this user's email, then delete them. */
    public static function redeemFor(User $user): void
    {
        $pending = static::where('email', strtolower($user->email))->get();

        foreach ($pending as $inv) {
            $user->entities()->syncWithoutDetaching([
                $inv->entity_id => ['role' => $inv->role, 'granted_by' => $inv->invited_by],
            ]);
            $inv->delete();
        }

        if ($pending->isNotEmpty()) {
            $user->forgetEntityRoles();
        }
    }
}
