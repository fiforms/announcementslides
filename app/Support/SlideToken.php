<?php

namespace App\Support;

use App\Models\Slide;
use Illuminate\Validation\Rule;

/**
 * Public slide identifiers: the unguessable token in /slides/{token} that
 * replaces the incrementing database id in every public URL (so slides can't
 * be walked in order and the slide count isn't disclosed).
 *
 * Generated tokens are 6 characters from an alphabet with no vowels (and
 * none of the digits that stand in for them), so they can't spell a word.
 * They always contain a letter, so they can never be mistaken for a numeric id.
 */
class SlideToken
{
    private const ALPHABET = 'bcdfghjkmnpqrstvwxz2567';

    public const LENGTH = 6;

    /** Paths under /slides/ that are routes, not slides. */
    private const RESERVED = ['download-zip', 'download-pptx', 'download-revelation'];

    /** A fresh token that no slide (soft-deleted ones included) already holds. */
    public static function generate(): string
    {
        do {
            $token = '';
            for ($i = 0; $i < self::LENGTH; $i++) {
                $token .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (! preg_match('/[a-z]/', $token) || self::taken($token));

        return $token;
    }

    public static function taken(string $token): bool
    {
        return Slide::withTrashed()->where('token', $token)->exists();
    }

    /** Validation rules for a custom token (contributor-chosen, e.g. "camp26"). */
    public static function rules(): array
    {
        return [
            'nullable', 'string', 'min:3', 'max:32',
            'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
            'regex:/[a-z]/',
            Rule::notIn(self::RESERVED),
            fn ($attribute, $value, $fail) => $value !== null && self::taken($value)
                ? $fail('That link name is already used by another slide.')
                : null,
        ];
    }
}
