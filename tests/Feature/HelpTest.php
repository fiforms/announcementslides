<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Help\HelpLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HelpTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/help-'.uniqid());
        File::makeDirectory($this->dir.'/en/media', 0777, true);
        File::makeDirectory($this->dir.'/es/media', 0777, true);
        File::makeDirectory($this->dir.'/media', 0777, true);
        config(['help.path' => $this->dir, 'help.default_locale' => 'en', 'help.locales' => ['en', 'es']]);

        $this->topic('en', 'welcome', "---\ntitle: Welcome\nsummary: Start here\ngroup: Basics\norder: 1\n---\n## Hello\n\nSee [other](help:other#part).\n");
        $this->topic('en', 'other', "---\ntitle: Other\norder: 2\n---\nBody\n");
        $this->topic('es', 'welcome', "---\ntitle: Bienvenido\norder: 1\n---\nHola\n");
        $this->topic('en', 'members', "---\ntitle: Members\naudience: users\n---\nsecret\n");
        $this->topic('en', 'admins', "---\ntitle: Admins\naudience: admin\n---\nsecret\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function topic(string $locale, string $slug, string $text): void
    {
        File::put("{$this->dir}/{$locale}/{$slug}.md", $text);
    }

    public function test_guests_see_only_everyone_topics_in_order(): void
    {
        $this->get(route('help.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Help/Index')
                ->where('topics.0.slug', 'welcome')
                ->where('topics.0.title', 'Welcome')
                ->where('topics.0.group', 'Basics')
                ->where('topics.1.slug', 'other')
                ->has('topics', 2));
    }

    public function test_audience_controls_who_can_read_a_topic(): void
    {
        $this->get(route('help.show', 'members'))->assertNotFound();

        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->get(route('help.show', 'members'))->assertOk();
        $this->actingAs($viewer)->get(route('help.show', 'admins'))->assertNotFound();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('help.show', 'admins'))->assertOk();
    }

    public function test_the_requested_language_is_used_with_english_fallback(): void
    {
        $this->get(route('help.show', ['topic' => 'welcome', 'lang' => 'es']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('topic.title', 'Bienvenido')
                ->where('topic.fallback', false)
                ->where('locale', 'es')
                ->where('langQuery.lang', 'es'));

        // "other" has no Spanish version: shown in English and flagged.
        $this->get(route('help.index', ['lang' => 'es']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('topics.1.slug', 'other')
                ->where('topics.1.fallback', true));

        $this->get(route('help.show', ['topic' => 'other', 'lang' => 'es']))
            ->assertInertia(fn (Assert $page) => $page->where('topic.fallback', true)->where('topic.locale', 'en'));
    }

    public function test_the_browser_language_is_used_when_none_is_chosen(): void
    {
        $this->get(route('help.show', 'welcome'), ['Accept-Language' => 'es-MX,es;q=0.9'])
            ->assertInertia(fn (Assert $page) => $page->where('topic.title', 'Bienvenido'));

        $this->get(route('help.show', 'welcome'), ['Accept-Language' => 'fr'])
            ->assertInertia(fn (Assert $page) => $page->where('topic.title', 'Welcome'));
    }

    public function test_the_language_switcher_lists_only_languages_with_content(): void
    {
        File::deleteDirectory($this->dir.'/es');

        $this->get(route('help.index'))
            ->assertInertia(fn (Assert $page) => $page->where('languages', ['en']));
    }

    public function test_topic_links_keep_the_explicit_language(): void
    {
        $html = $this->get(route('help.show', ['topic' => 'welcome', 'lang' => 'en']))
            ->viewData('page')['props']['topic']['html'];

        $this->assertStringContainsString('href="'.url('/help/other').'?lang=en#part"', $html);
        $this->assertStringContainsString('id="hello"', $html);
    }

    public function test_unknown_or_malformed_topics_are_404(): void
    {
        $this->get('/help/nope')->assertNotFound();
        $this->get('/help/..%2F..%2F.env')->assertNotFound();
        $this->get('/help/Bad_Slug')->assertNotFound();
    }

    public function test_raw_html_and_unsafe_links_are_not_rendered(): void
    {
        $this->topic('en', 'evil', "---\ntitle: Evil\n---\n<script>alert(1)</script>\n\n[x](javascript:alert(1))\n");

        $html = $this->get(route('help.show', 'evil'))->viewData('page')['props']['topic']['html'];

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_images_and_videos_resolve_per_language_with_shared_fallback(): void
    {
        File::put($this->dir.'/en/media/obs.png', 'png');
        File::put($this->dir.'/es/media/obs.png', 'png-es');
        File::put($this->dir.'/media/logo.png', 'logo');
        File::put($this->dir.'/en/media/pi.mp4', 'mp4');
        File::put($this->dir.'/en/media/pi.vtt', "WEBVTT\n");
        $this->topic('en', 'pics', "---\ntitle: Pics\n---\n![OBS settings](media:obs.png)\n\n![Logo](media:logo.png)\n\n![Imaging](media:pi.mp4)\n\n![Gone](media:missing.png)\n\n![Remote](https://example.org/clip.webm)\n");
        $this->topic('es', 'pics', "---\ntitle: Fotos\n---\n![OBS](media:obs.png)\n\n![Imaging](media:pi.mp4)\n");

        $en = $this->get(route('help.show', ['topic' => 'pics', 'lang' => 'en']))->viewData('page')['props']['topic']['html'];
        $this->assertStringContainsString('<img src="'.url('/help-media/en/media/obs.png').'" alt="OBS settings"', $en);
        $this->assertStringContainsString(url('/help-media/media/logo.png'), $en);
        $this->assertStringContainsString('<video controls', $en);
        $this->assertStringContainsString(url('/help-media/en/media/pi.mp4'), $en);
        $this->assertStringContainsString('<track kind="captions"', $en);
        $this->assertStringContainsString('help-media-missing', $en);
        $this->assertStringContainsString('<video controls preload="metadata" playsinline src="https://example.org/clip.webm"', $en);

        $es = $this->get(route('help.show', ['topic' => 'pics', 'lang' => 'es']))->viewData('page')['props']['topic']['html'];
        $this->assertStringContainsString(url('/help-media/es/media/obs.png'), $es);
        $this->assertStringContainsString(url('/help-media/en/media/pi.mp4'), $es);
    }

    public function test_the_media_route_serves_only_allowed_files(): void
    {
        File::put($this->dir.'/en/media/ok.png', 'png');
        File::put($this->dir.'/en/media/vector.svg', '<svg/>');
        File::put($this->dir.'/en/secret.md', 'x');

        $this->get('/help-media/en/media/ok.png')->assertOk();
        $this->get('/help-media/en/media/vector.svg')->assertNotFound();
        $this->get('/help-media/en/secret.md')->assertNotFound();
        $this->get('/help-media/en/media/missing.png')->assertNotFound();
        $this->get('/help-media/fr/media/ok.png')->assertNotFound();
        $this->get('/help-media/media/..%2F..%2F.env')->assertNotFound();
        $this->get('/help-media/media/../en/secret.md')->assertNotFound();
    }

    public function test_the_bundled_english_topics_are_well_formed(): void
    {
        config(['help.path' => resource_path('help')]);
        $library = HelpLibrary::make();
        $admin = User::factory()->create(['role' => 'admin']);

        $topics = $library->topics('en', $admin);
        $this->assertGreaterThan(0, $topics->count());

        foreach ($topics as $topic) {
            $found = $library->find($topic['slug'], 'en', $admin);
            $this->assertNotNull($found);
            $this->assertNotSame($topic['slug'], $found['title'], "{$topic['slug']} needs a title");
            $this->assertStringNotContainsString('help-media-missing', $found['html'], "{$topic['slug']} references missing media");
            // Every help: link must point at a real topic.
            preg_match_all('#href="'.preg_quote(url('/help/'), '#').'([a-z0-9-]+)#', $found['html'], $links);
            foreach ($links[1] as $slug) {
                $this->assertTrue($topics->contains('slug', $slug), "{$topic['slug']} links to unknown topic {$slug}");
            }
        }
    }
}
