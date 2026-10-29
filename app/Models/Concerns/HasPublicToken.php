<?php

namespace App\Models\Concerns;

use App\Support\PublicToken;

/**
 * Gives a model its public `token` (see PublicToken): generated at creation
 * unless the creator was allowed to choose one (already validated for
 * uniqueness), and never changed afterwards, since it is the record's
 * shareable link.
 */
trait HasPublicToken
{
    public static function bootHasPublicToken(): void
    {
        static::creating(function ($model) {
            $model->token = filled($model->token) ? $model->token : PublicToken::generate(static::class);
        });

        static::updating(function ($model) {
            if ($model->isDirty('token')) {
                $model->token = $model->getOriginal('token');
            }
        });
    }
}
