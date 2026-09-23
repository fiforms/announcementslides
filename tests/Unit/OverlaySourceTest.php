<?php

namespace Tests\Unit;

use App\Services\OverlaySource;
use App\Services\SvgSanitizer;
use DOMDocument;
use PHPUnit\Framework\TestCase;

class OverlaySourceTest extends TestCase
{
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1920 1080"><rect id="as-el-1" x="10" y="10" width="100" height="50" fill="#fff"/><text x="5" y="5">Hi &amp; bye</text></svg>';

    private function source(): array
    {
        return ['v' => 1, 'canvas' => ['w' => 1920, 'h' => 1080], 'elements' => [['id' => 'as-el-1', 'type' => 'rect', 'text' => 'a ]]> b <script>']]];
    }

    public function test_embed_then_extract_round_trips_the_source(): void
    {
        $svc = new OverlaySource();
        $embedded = $svc->embed(self::SVG, $this->source());

        $this->assertStringContainsString('id="as-overlay-source"', $embedded);
        $this->assertStringNotContainsString('<script>', $embedded);

        $extracted = $svc->extract($embedded);
        $this->assertSame($this->source()['elements'], $extracted['source']['elements']);
        $this->assertStringNotContainsString('as-overlay-source', $extracted['svg']);
    }

    public function test_body_changes_invalidate_the_source(): void
    {
        $svc = new OverlaySource();
        $embedded = $svc->embed(self::SVG, $this->source());
        $tampered = str_replace('width="100"', 'width="101"', $embedded);

        $this->assertNull($svc->extract($tampered)['source']);
    }

    public function test_missing_or_malformed_metadata_yields_no_source(): void
    {
        $svc = new OverlaySource();

        $this->assertNull($svc->extract(self::SVG)['source']);

        $bad = str_replace('<rect', '<metadata id="as-overlay-source">{not json</metadata><rect', self::SVG);
        $this->assertNull($svc->extract($bad)['source']);
        $this->assertNull($svc->extract('not xml at all')['source']);
    }

    public function test_canonical_body_is_stable_across_serializations(): void
    {
        $svc = new OverlaySource();
        $a = new DOMDocument();
        $a->loadXML(self::SVG);
        $b = new DOMDocument();
        $b->loadXML(str_replace(['x="10" y="10"', '"'], ["y='10' x='10'", "'"], self::SVG));

        $this->assertSame($svc->canonicalBody($a), $svc->canonicalBody($b));
    }

    public function test_source_survives_re_sanitizing_an_embedded_file(): void
    {
        $svc = new OverlaySource();
        $sanitizer = new SvgSanitizer();
        $embedded = $svc->embed($sanitizer->sanitize(self::SVG), $this->source());

        $resanitized = $sanitizer->sanitize($embedded);

        $this->assertNotNull($svc->extract($resanitized)['source']);
    }

    public function test_sanitizer_strips_scripts_handlers_and_remote_hrefs(): void
    {
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10" onload="alert(1)">'
            . '<script>alert(1)</script>'
            . '<image href="https://evil.example/x.png" width="1" height="1"/>'
            . '<image xlink:href="data:image/png;base64,AAAA" width="1" height="1"/>'
            . '<a href="javascript:alert(1)"><rect width="1" height="1"/></a></svg>';

        $clean = (new SvgSanitizer())->sanitize($dirty);

        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('onload', $clean);
        $this->assertStringNotContainsString('evil.example', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('data:image/png;base64,AAAA', $clean);
    }

    public function test_sanitizer_rejects_non_svg(): void
    {
        $sanitizer = new SvgSanitizer();

        $this->assertNull($sanitizer->sanitize('<html><body/></html>'));
        $this->assertNull($sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg"/>'));
        $this->assertNull($sanitizer->sanitize('garbage'));
    }
}
