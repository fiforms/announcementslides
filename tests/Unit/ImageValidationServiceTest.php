<?php

namespace Tests\Unit;

use App\Services\ImageValidationService;
use Tests\TestCase;

class ImageValidationServiceTest extends TestCase
{
    public function test_low_resolution_message_rounds_megapixels(): void
    {
        $result = (new ImageValidationService())->validateDimensions(1254, 1254, 200_000);

        $this->assertSame('warning', $result['status']);
        $this->assertContains('Low resolution (1.6MP) — image may appear pixelated', $result['issues']);
    }
}
