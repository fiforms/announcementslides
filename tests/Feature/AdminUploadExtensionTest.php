<?php

namespace Tests\Feature;

use App\Models\SlideMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The admin upload form stores files on the web-served public disk. Laravel's
 * `mimes:` rule sniffs content, so a real PNG named evil.html passes it; the
 * stored extension must therefore come from the sniffed mime type, not the
 * client's filename.
 */
class AdminUploadExtensionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_png_named_html_is_stored_as_png(): void
    {
        Storage::fake('public');
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.slides.store'), [
            'files' => [UploadedFile::fake()->image('evil.html', 10, 10)->mimeType('image/png')],
            'title' => 'Probe',
        ])->assertSessionHasNoErrors();

        $media = SlideMedia::firstOrFail();
        $this->assertStringEndsWith('.png', $media->disk_path);
        $this->assertStringEndsNotWith('.html', $media->disk_path);
        $this->assertSame('evil.html', $media->original_filename);
        Storage::disk('public')->assertExists($media->disk_path);
    }
}
