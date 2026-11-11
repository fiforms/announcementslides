<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An uploaded SVG must be sanitized before it is web-reachable, not merely
 * when it is later attached to a slide: otherwise an upload that is never
 * attached stays on the public disk, raw, at a URL the uploader knows.
 * See "SVG security model" in ARCHITECTURE.md.
 */
class SvgUploadSanitizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function upload(string $body)
    {
        $user = User::factory()->create(['role' => 'admin']);

        return $this->actingAs($user)->post('/uploads/chunk', [
            'upload_id'    => (string) Str::uuid(),
            'chunk_index'  => 0,
            'total_chunks' => 1,
            'filename'     => 'overlay.svg',
            'media_type'   => 'slide-overlay',
            'mime_type'    => 'image/svg+xml',
            'chunk'        => UploadedFile::fake()->createWithContent('overlay.svg', $body),
        ]);
    }

    public function test_script_is_stripped_before_the_file_reaches_the_public_disk(): void
    {
        $res = $this->upload('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
            .'<script>alert(1)</script><rect width="5" height="5" onload="alert(2)"/></svg>');

        $res->assertOk();
        $stored = Storage::disk('public')->get($res->json('disk_path'));
        $this->assertStringNotContainsString('script', $stored);
        $this->assertStringNotContainsString('onload', $stored);
        $this->assertStringContainsString('<rect', $stored);
        $this->assertSame(strlen($stored), $res->json('file_size'));
    }

    public function test_an_embedded_data_font_survives_for_future_custom_fonts(): void
    {
        $res = $this->upload('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
            .'<style>@font-face{font-family:F;src:url(data:font/woff2;base64,AAAA)} text{font-family:F}</style>'
            .'<text>hi</text></svg>');

        $res->assertOk();
        $this->assertStringContainsString('data:font/woff2', Storage::disk('public')->get($res->json('disk_path')));
    }

    public function test_remote_css_references_are_removed(): void
    {
        $res = $this->upload('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
            .'<style>@import url(https://evil.example/x.css); rect{fill:red}</style><rect width="5" height="5"/></svg>');

        $res->assertOk();
        $this->assertStringNotContainsString('evil.example', Storage::disk('public')->get($res->json('disk_path')));
    }

    public function test_something_that_is_not_an_svg_is_rejected_and_leaves_nothing_behind(): void
    {
        $res = $this->upload('<html><script>alert(1)</script></html>');

        $res->assertStatus(422);
        $this->assertSame([], Storage::disk('public')->allFiles('slides'));
        $this->assertSame([], glob(storage_path('app/chunks/*')) ?: []);
    }

    public function test_an_xxe_document_is_rejected(): void
    {
        $res = $this->upload('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            .'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><text>&x;</text></svg>');

        $res->assertStatus(422);
        $this->assertSame([], Storage::disk('public')->allFiles('slides'));
    }

    public function test_an_oversized_svg_is_rejected_without_being_parsed(): void
    {
        $res = $this->upload('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><!--'
            .str_repeat('a', 5 * 1024 * 1024 + 1).'--></svg>');

        $res->assertStatus(422);
        $this->assertSame([], Storage::disk('public')->allFiles('slides'));
    }
}
