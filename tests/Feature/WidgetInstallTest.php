<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Widget;
use App\Services\Widgets\WidgetInstaller;
use App\Services\Widgets\WidgetPackageException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class WidgetInstallTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->tmp = sys_get_temp_dir() . '/widget-test-' . uniqid();
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp . '/*') ?: []);
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function manifest(array $overrides = []): array
    {
        return array_replace([
            'id' => 'demo', 'name' => 'Demo', 'version' => '1.0.0',
            'entry' => 'widget.js', 'icon' => 'icon.png',
        ], $overrides);
    }

    /** @param array<string, string> $files */
    private function zip(array $files, ?callable $tweak = null): string
    {
        $path = $this->tmp . '/' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($files as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $tweak && $tweak($zip);
        $zip->close();

        return $path;
    }

    private function package(array $manifest = [], array $extra = []): array
    {
        return [
            'manifest.json' => json_encode($this->manifest($manifest)),
            'widget.js'     => 'export function mount() {}',
            'icon.png'      => 'png',
            ...$extra,
        ];
    }

    private function installZip(string $path): Widget
    {
        return app(WidgetInstaller::class)->installZip($path);
    }

    private function assertRejected(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail('Expected the package to be rejected.');
        } catch (WidgetPackageException $e) {
            $this->assertStringContainsString($needle, implode(' | ', $e->errors));
        }
    }

    public function test_the_bundled_sample_widgets_install(): void
    {
        $installer = app(WidgetInstaller::class);
        $clock = $installer->installDirectory(base_path('resources/widgets/clock'));
        $calendar = $installer->installDirectory(base_path('resources/widgets/calendar'));

        $this->assertSame('clock', $clock->slug);
        $this->assertTrue($clock->enabled);
        $this->assertSame(['icon.png', 'manifest.json', 'widget.js'], $calendar->files);
        Storage::disk('local')->assertExists('widgets/calendar/1.0.0/widget.js');
        $this->assertSame('{ics}', $calendar->manifest['endpoints']['events']['url']);
    }

    public function test_a_single_wrapping_folder_is_stripped(): void
    {
        $files = collect($this->package())->mapWithKeys(fn ($b, $n) => ["demo/{$n}" => $b])->all();
        $widget = $this->installZip($this->zip($files + ['__MACOSX/demo/._widget.js' => 'junk']));

        $this->assertSame(['icon.png', 'manifest.json', 'widget.js'], $widget->files);
    }

    public function test_path_traversal_entries_are_rejected(): void
    {
        $this->assertRejected(fn () => $this->installZip($this->zip($this->package() + ['../evil.js' => 'x'])), 'unsafe path');
        $this->assertRejected(fn () => $this->installZip($this->zip($this->package() + ['a/../../evil.js' => 'x'])), 'unsafe path');
        $this->assertRejected(fn () => $this->installZip($this->zip($this->package() + ['/abs.js' => 'x'])), 'unsafe path');
        Storage::disk('local')->assertMissing('evil.js');
        $this->assertSame(0, Widget::count());
    }

    public function test_symlink_entries_are_rejected(): void
    {
        $path = $this->zip($this->package() + ['link.js' => '/etc/passwd'], function (ZipArchive $zip) {
            $zip->setExternalAttributesName('link.js', ZipArchive::OPSYS_UNIX, (0o120777) << 16);
        });

        $this->assertRejected(fn () => $this->installZip($path), 'symbolic link');
    }

    public function test_disallowed_file_types_are_rejected(): void
    {
        $this->assertRejected(fn () => $this->installZip($this->zip($this->package() + ['run.php' => '<?php'])), 'file type');
        $this->assertRejected(fn () => $this->installZip($this->zip($this->package() + ['page.html' => '<b>'])), 'file type');
    }

    public function test_oversized_packages_are_rejected(): void
    {
        config(['widgets.max_unpacked_bytes' => 1000]);
        $this->assertRejected(fn () => $this->installZip($this->zip($this->package() + ['big.js' => str_repeat('a', 5000)])), 'too large');
    }

    public function test_manifest_problems_are_all_reported(): void
    {
        $path = $this->zip($this->package(['id' => 'Bad Id!', 'version' => 'one', 'entry' => 'missing.js']));

        try {
            $this->installZip($path);
            $this->fail('Expected rejection.');
        } catch (WidgetPackageException $e) {
            $all = implode(' | ', $e->errors);
            $this->assertStringContainsString('"id"', $all);
            $this->assertStringContainsString('"version"', $all);
            $this->assertStringContainsString('"entry"', $all);
        }
    }

    public function test_endpoint_templates_must_fix_the_host(): void
    {
        $params = ['host' => ['type' => 'string'], 'q' => ['type' => 'string']];
        $steerable = $this->package(['parameters' => $params, 'endpoints' => ['e' => ['url' => 'https://{host}/x', 'expect' => 'json']]]);
        $this->assertRejected(fn () => $this->installZip($this->zip($steerable)), 'fixed host');

        $wholeString = $this->package(['parameters' => $params, 'endpoints' => ['e' => ['url' => '{q}', 'expect' => 'json']]]);
        $this->assertRejected(fn () => $this->installZip($this->zip($wholeString)), 'single url parameter');

        $http = $this->package(['parameters' => $params, 'endpoints' => ['e' => ['url' => 'http://api.example.com/{q}', 'expect' => 'json']]]);
        $this->assertRejected(fn () => $this->installZip($this->zip($http)), 'fixed host');

        $ok = $this->package(['parameters' => $params, 'endpoints' => ['e' => ['url' => 'https://api.example.com/v1?q={q}', 'expect' => 'json']]]);
        $this->assertSame('demo', $this->installZip($this->zip($ok))->slug);
    }

    public function test_upgrading_replaces_the_version_and_keeps_admin_state(): void
    {
        $widget = $this->installZip($this->zip($this->package()));
        $widget->update(['enabled' => false, 'extra_allow' => ['x' => ['https://a.example/']]]);

        $upgraded = $this->installZip($this->zip($this->package(['version' => '1.1.0'])));

        $this->assertSame($widget->id, $upgraded->id);
        $this->assertSame('1.1.0', $upgraded->version);
        $this->assertFalse($upgraded->enabled);
        $this->assertSame(['x' => ['https://a.example/']], $upgraded->extra_allow);
        Storage::disk('local')->assertMissing('widgets/demo/1.0.0/widget.js');
        Storage::disk('local')->assertExists('widgets/demo/1.1.0/widget.js');
    }

    public function test_only_site_admins_can_upload(): void
    {
        $zip = $this->zip($this->package());
        $upload = fn () => ['package' => new UploadedFile($zip, 'demo.zip', 'application/zip', null, true)];

        $this->actingAs(User::factory()->create(['role' => 'contributor']))
            ->post(route('admin.widgets.store'), $upload())->assertForbidden();
        $this->assertSame(0, Widget::count());

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.widgets.store'), $upload())->assertSessionHasNoErrors();
        $this->assertSame(1, Widget::count());
    }

    public function test_assets_are_served_only_for_listed_files_of_enabled_widgets(): void
    {
        $widget = $this->installZip($this->zip($this->package()));
        Storage::disk('local')->put('widgets/demo/1.0.0/secret.js', 'not in manifest');

        $this->get($widget->entryUrl())
            ->assertOk()
            ->assertHeader('Content-Type', 'text/javascript; charset=utf-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('widgets.asset', ['slug' => 'demo', 'version' => '1.0.0', 'path' => 'secret.js']))->assertNotFound();
        $this->get(route('widgets.asset', ['slug' => 'demo', 'version' => '1.0.0', 'path' => '../../../.env']))->assertNotFound();
        $this->get(route('widgets.asset', ['slug' => 'demo', 'version' => '0.9.0', 'path' => 'widget.js']))->assertNotFound();

        $widget->update(['enabled' => false]);
        $this->get($widget->entryUrl())->assertNotFound();
    }
}
