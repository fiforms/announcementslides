<?php

namespace App\Support;

use App\Models\Slide;

/** Slide flavor of PublicToken: adds the /slides/ path segments that are routes, not slides. */
class SlideToken
{
    /** Paths under /slides/ that are routes, not slides. */
    private const RESERVED = ['download-zip', 'download-pptx', 'download-revelation'];

    public static function generate(): string
    {
        return PublicToken::generate(Slide::class);
    }

    public static function taken(string $token): bool
    {
        return PublicToken::taken(Slide::class, $token);
    }

    public static function rules(): array
    {
        return PublicToken::rules(Slide::class, self::RESERVED);
    }
}
