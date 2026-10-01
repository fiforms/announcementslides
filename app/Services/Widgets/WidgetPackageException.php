<?php

namespace App\Services\Widgets;

use RuntimeException;

/**
 * A widget package, manifest or parameter set failed validation. Carries
 * every problem found so an admin (or overlay editor) sees them all at once.
 */
class WidgetPackageException extends RuntimeException
{
    /** @param string[] $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
