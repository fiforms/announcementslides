<?php

namespace Tests\Unit;

use App\Services\Widgets\WidgetManifest;
use App\Services\Widgets\WidgetPackageException;
use PHPUnit\Framework\TestCase;

class WidgetSizingManifestTest extends TestCase
{
    private function validate(?array $sizing): array
    {
        return WidgetManifest::validate(array_filter([
            'id' => 'demo', 'name' => 'Demo', 'version' => '1.0.0',
            'entry' => 'widget.js', 'icon' => 'icon.png',
            'parameters' => ['mode' => ['type' => 'enum', 'options' => ['a', 'b']], 'title' => ['type' => 'string']],
            'sizing' => $sizing,
        ]), ['widget.js', 'icon.png']);
    }

    private function errors(array $sizing): array
    {
        try {
            $this->validate($sizing);
        } catch (WidgetPackageException $e) {
            return $e->errors ?? [$e->getMessage()];
        }

        return [];
    }

    public function test_sizing_is_optional(): void
    {
        $this->assertNull($this->validate(null)['sizing']);
    }

    public function test_valid_sizing_with_modes_is_normalized(): void
    {
        $out = $this->validate([
            'aspect' => ['min' => 1.5, 'max' => 4], 'minWidth' => 0.2, 'by' => 'mode',
            'modes' => ['a' => ['aspect' => 1, 'minWidth' => 0.1], 'b' => ['aspect' => ['max' => 2]]],
        ])['sizing'];

        $this->assertSame(['min' => 1.5, 'max' => 4], $out['aspect']);
        $this->assertSame(['min' => 1, 'max' => 1], $out['modes']['a']['aspect']);
        $this->assertEquals(['min' => 0.05, 'max' => 2], $out['modes']['b']['aspect']);
        $this->assertSame('mode', $out['by']);
    }

    public function test_rejects_bad_values(): void
    {
        $this->assertNotEmpty($this->errors(['aspect' => ['min' => 3, 'max' => 2]]));
        $this->assertNotEmpty($this->errors(['aspect' => ['min' => 0, 'max' => 2]]));
        $this->assertNotEmpty($this->errors(['minWidth' => 0]));
        $this->assertNotEmpty($this->errors(['minWidth' => 1.5]));
        $this->assertNotEmpty($this->errors(['bogus' => 1]));
    }

    public function test_by_must_be_an_enum_and_modes_must_be_its_options(): void
    {
        $this->assertNotEmpty($this->errors(['by' => 'title', 'modes' => ['a' => ['minWidth' => 0.1]]]));
        $this->assertNotEmpty($this->errors(['by' => 'mode', 'modes' => ['zzz' => ['minWidth' => 0.1]]]));
        $this->assertNotEmpty($this->errors(['modes' => ['a' => ['minWidth' => 0.1]]]));
    }
}
