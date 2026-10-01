<?php

namespace App\Services\Widgets;

use RuntimeException;

/**
 * A widget data fetch was refused or failed. `reason` is a stable code the
 * widget-data endpoint returns to the browser (never upstream details).
 */
class WidgetFetchException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $upstreamStatus = null)
    {
        parent::__construct($reason . ($upstreamStatus ? " ({$upstreamStatus})" : ''));
    }
}
