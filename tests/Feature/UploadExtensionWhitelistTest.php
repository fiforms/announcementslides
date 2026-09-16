<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The assembled upload lands on the `public` disk, which is web-served via
 * the storage:link symlink. The stored extension therefore has to come from
 * the validated mime type and never from the client's filename — otherwise
 * any authenticated user can choose it, and a .php under the document root
 * is executed by the default nginx/Apache Laravel config.
 */
class UploadExtensionWhitelistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** The lowest-privileged role that can reach /uploads/chunk. */
    private function viewer(): User
    {
        return User::factory()->create(['role' => 'viewer']);
    }

    private function upload(User $as, string $filename, string $mime, string $body = 'payload')
    {
        return $this->actingAs($as)->post('/uploads/chunk', [
            'upload_id'    => (string) Str::uuid(),
            'chunk_index'  => 0,
            'total_chunks' => 1,
            'filename'     => $filename,
            'media_type'   => 'slide',
            'mime_type'    => $mime,
            'chunk'        => UploadedFile::fake()->createWithContent($filename, $body),
        ]);
    }

    public function test_a_php_filename_is_not_stored_as_php(): void
    {
        $res = $this->upload($this->viewer(), 'payload.php', 'image/png', '<?php echo 1; ?>');

        $res->assertOk();
        $this->assertStringEndsWith('.png', $res->json('disk_path'));
        $this->assertStringNotContainsString('.php', $res->json('disk_path'));
    }

    public static function dangerousNames(): array
    {
        return [
            'php'        => ['x.php'],
            'phtml'      => ['x.phtml'],
            'php8'       => ['x.php8'],
            'htaccess'   => ['.htaccess'],
            'html'       => ['x.html'],
            'double ext' => ['x.png.php'],
            'no ext'     => ['x'],
            'trailing .' => ['x.'],
        ];
    }

    #[DataProvider('dangerousNames')]
    public function test_the_stored_extension_always_comes_from_the_mime(string $name): void
    {
        $res = $this->upload($this->viewer(), $name, 'image/png');

        $res->assertOk();
        $this->assertMatchesRegularExpression(
            '#^slides/[0-9a-f\-]{36}\.png$#',
            $res->json('disk_path'),
            "Client filename '{$name}' influenced the stored path"
        );
    }

    public function test_only_files_with_safe_extensions_exist_on_disk(): void
    {
        $this->upload($this->viewer(), 'payload.php', 'image/png');
        $this->upload($this->viewer(), 'clip.mov', 'video/quicktime');

        foreach (Storage::disk('public')->allFiles('slides') as $path) {
            $this->assertMatchesRegularExpression('#\.(png|mov)$#', $path, "Unsafe file on disk: {$path}");
        }
    }

    public function test_the_original_filename_is_still_preserved_for_display(): void
    {
        $res = $this->upload($this->viewer(), 'Christmas Flyer.PNG', 'image/png');

        $res->assertOk()->assertJsonPath('original_filename', 'Christmas Flyer.PNG');
    }

    public function test_each_allowed_mime_maps_to_its_expected_extension(): void
    {
        foreach (['image/jpeg' => 'jpg', 'image/png' => 'png', 'video/mp4' => 'mp4'] as $mime => $ext) {
            $res = $this->upload($this->viewer(), 'whatever.php', $mime);

            $res->assertOk();
            $this->assertStringEndsWith(".{$ext}", $res->json('disk_path'));
        }
    }

    public function test_a_mime_outside_the_media_types_list_is_rejected(): void
    {
        $this->upload($this->viewer(), 'x.php', 'application/x-httpd-php')
            ->assertSessionHasErrors('mime_type');
    }

    public function test_finalize_rejects_a_disk_path_with_an_unexpected_extension(): void
    {
        $uuid = (string) Str::uuid();
        Storage::disk('public')->put("slides/{$uuid}.php", '<?php');

        $this->actingAs($this->viewer())->post('/uploads/finalize', [
            'title'   => 'T',
            'uploads' => [[
                'filename'          => "{$uuid}.php",
                'disk_path'         => "slides/{$uuid}.php",
                'original_filename' => 'x.php',
                'file_size'         => 5,
                'mime_type'         => 'image/png',
            ]],
        ])->assertSessionHasErrors(['uploads.0.filename', 'uploads.0.disk_path']);
    }

    public function test_every_configured_mime_has_an_extension(): void
    {
        $map = config('slides.mime_extensions');

        foreach (config('slides.media_types') as $type => $def) {
            foreach ($def['mimes'] as $mime) {
                $this->assertArrayHasKey($mime, $map, "media_type '{$type}' allows {$mime} with no extension mapping");
            }
        }
    }
}
