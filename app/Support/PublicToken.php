<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Public identifiers: the unguessable token that replaces an incrementing
 * database id in public URLs (so records can't be walked in order and
 * counts aren't disclosed) for slides, shows and promo shows.
 *
 * Generated tokens are 6 characters from an alphabet with no vowels (and
 * none of the digits that stand in for them), so they can't spell a word.
 * They always contain a letter, so they can never be mistaken for a numeric id.
 */
class PublicToken
{
    private const ALPHABET = 'bcdfghjkmnpqrstvwxz2567';

    public const LENGTH = 6;

    /** A fresh token that no row of $model (soft-deleted ones included) already holds. */
    public static function generate(string $model): string
    {
        do {
            $token = '';
            for ($i = 0; $i < self::LENGTH; $i++) {
                $token .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (! preg_match('/[a-z]/', $token) || self::taken($model, $token));

        return $token;
    }

    public static function taken(string $model, string $token): bool
    {
        $query = $model::query();
        if (method_exists($model, 'bootSoftDeletes')) {
            $query->withTrashed();
        }

        return $query->where('token', $token)->exists();
    }

    /**
     * Validation rules for a custom (creator-chosen, e.g. "camp26") token.
     *
     * @param  string[]  $reserved  values that would collide with a route
     */
    public static function rules(string $model, array $reserved = []): array
    {
        return [
            'nullable', 'string', 'min:3', 'max:32',
            'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
            'regex:/[a-z]/',
            Rule::notIn($reserved),
            fn ($attribute, $value, $fail) => $value !== null && self::taken($model, $value)
                ? $fail('That link name is already used.')
                : null,
        ];
    }
}
