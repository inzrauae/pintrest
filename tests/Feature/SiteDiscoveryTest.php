<?php

namespace Tests\Feature;

use App\Jobs\ProcessUrlSubmission;
use App\Models\GeneratedPin;
use App\Models\PinTemplate;
use App\Models\UrlBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SiteDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function xml(string $body): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response('<?xml version="1.0" encoding="UTF-8"?>'.$body, 200, ['Content-Type' => 'application/xml']);
    }

    public function test_sitemap_index_is_followed_and_non_content_urls_are_filtered(): void
    {
        Http::fake([
            'https://example.com/sitemap_index.xml' => $this->xml('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://example.com/post-sitemap.xml</loc></sitemap></sitemapindex>'),
            'https://example.com/post-sitemap.xml' => $this->xml('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://example.com/old-post</loc><lastmod>2024-01-01</lastmod></url>'
                .'<url><loc>https://example.com/new-post</loc><lastmod>2026-10-01</lastmod></url>'
                .'<url><loc>https://example.com/tag/basil</loc></url>'
                .'<url><loc>https://example.com/wp-content/uploads/a.jpg</loc></url>'
                .'<url><loc>https://other-site.com/post</loc></url>'
                .'</urlset>'),
        ]);
        $user = User::factory()->create();

        $data = $this->actingAs($user)->postJson('/api/sites/discover', ['url' => 'https://example.com/sitemap_index.xml'])->assertOk()->json('data');

        $this->assertSame('sitemap', $data['source']);
        $this->assertSame(['https://example.com/new-post', 'https://example.com/old-post'], $data['urls'], 'newest first, junk removed');
    }

    public function test_website_url_uses_robots_txt_sitemap(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response("User-agent: *\nSitemap: https://example.com/custom-map.xml\n", 200, ['Content-Type' => 'text/plain']),
            'https://example.com/custom-map.xml' => $this->xml('<urlset><url><loc>https://www.example.com/recipes/soup</loc></url><url><loc>https://example.com/about</loc></url></urlset>'),
        ]);
        $user = User::factory()->create();

        $data = $this->actingAs($user)->postJson('/api/sites/discover', ['url' => 'https://example.com', 'include' => '/recipes/'])->assertOk()->json('data');

        $this->assertSame(['https://www.example.com/recipes/soup'], $data['urls']);
    }

    public function test_falls_back_to_crawling_same_site_links(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/*.xml' => Http::response('', 404),
            'https://example.com/' => Http::response('<html><body><a href="/a">A</a><a href="b">B</a><a href="https://elsewhere.com/x">X</a><a href="/login">L</a></body></html>', 200, ['Content-Type' => 'text/html']),
            'https://example.com/*' => Http::response('<html><body><a href="/c">C</a></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $user = User::factory()->create();

        $data = $this->actingAs($user)->postJson('/api/sites/discover', ['url' => 'https://example.com/'])->assertOk()->json('data');

        $this->assertSame('crawl', $data['source']);
        $this->assertEqualsCanonicalizing(['https://example.com/', 'https://example.com/a', 'https://example.com/b', 'https://example.com/c'], $data['urls']);
    }

    public function test_private_addresses_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/sites/discover', ['url' => 'http://10.0.0.1/sitemap.xml'])->assertUnprocessable()->assertJsonValidationErrors('url');
    }

    public function test_sitemap_urls_pointing_to_private_hosts_are_not_fetched(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => $this->xml('<sitemapindex><sitemap><loc>http://169.254.169.254/latest.xml</loc></sitemap></sitemapindex>'),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/sites/discover', ['url' => 'https://example.com/sitemap.xml'])->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
    }

    public function test_import_creates_batch_with_template_and_generates_pins(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => $this->xml('<urlset><url><loc>https://example.com/basil</loc></url><url><loc>https://example.com/mint</loc></url></urlset>'),
            'https://example.com/img/*' => fn () => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
            'https://example.com/*' => fn () => Http::response($this->articleHtml(), 200, ['Content-Type' => 'text/html']),
        ]);
        $user = User::factory()->create();
        $template = PinTemplate::factory()->create(['user_id' => $user->id]);

        $id = $this->actingAs($user)->postJson('/api/sites/import', [
            'url' => 'https://example.com/sitemap.xml',
            'template_id' => $template->id,
            'image_mode' => 'extract',
        ])->assertCreated()->json('data.id');

        $batch = UrlBatch::find($id);
        $this->assertSame(2, $batch->total);
        $this->assertSame('completed', $batch->status);
        $pins = GeneratedPin::where('user_id', $user->id)->get();
        $this->assertCount(2, $pins);
        $this->assertTrue($pins->every(fn ($p) => $p->status === 'draft' && $p->pin_template_id === $template->id));
    }

    public function test_import_rejects_another_users_template(): void
    {
        $user = User::factory()->create();
        $other = PinTemplate::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAs($user)->postJson('/api/sites/import', ['url' => 'https://example.com', 'urls' => ['https://example.com/a'], 'template_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors('template_id');
    }

    public function test_bulk_paste_of_copied_sitemap_keeps_only_page_urls(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $pasted = "URL\tLast modified\tChange frequency\tPriority\n"
            ."https://example.com/\t2026-10-29\tweekly\t1.0\n"
            ."https://example.com/pharmacy-pos-system\t2026-03-31\tmonthly\t0.95\n"
            ."https://example.com/wp-content/uploads/logo.png\t2026-02-27\tmonthly\t0.8\n";

        $response = $this->actingAs($user)->postJson('/api/urls/bulk', ['urls' => $pasted])->assertCreated();

        $this->assertSame(1, $response->json('ignored'));
        $this->assertSame(['https://example.com/', 'https://example.com/pharmacy-pos-system'], UrlBatch::find($response->json('data.id'))->submissions()->orderBy('id')->pluck('url')->all());
    }

    public function test_bulk_paste_of_sitemap_xml_and_sitemap_url(): void
    {
        Queue::fake();
        Http::fake([
            'https://example.com/sitemap.xml' => $this->xml('<urlset><url><loc>https://example.com/from-sitemap</loc></url></urlset>'),
        ]);
        $user = User::factory()->create();
        $xml = '<urlset xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"><url><loc>https://example.com/a?x=1&amp;y=2</loc><image:image><image:loc>https://example.com/a.jpg</image:loc></image:image><priority>0.9</priority></url></urlset>';

        $id = $this->actingAs($user)->postJson('/api/urls/bulk', ['urls' => $xml."\nhttps://example.com/sitemap.xml"])->assertCreated()->json('data.id');

        $this->assertSame(['https://example.com/a?x=1&y=2', 'https://example.com/from-sitemap'], UrlBatch::find($id)->submissions()->orderBy('id')->pluck('url')->all());
    }

    public function test_bulk_with_no_urls_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/urls/bulk', ['urls' => "2026-10-29 weekly 1.0"])->assertUnprocessable()->assertJsonValidationErrors('urls');
    }

    public function test_command_only_queues_new_pages(): void
    {
        Queue::fake();
        Http::fake([
            'https://example.com/sitemap.xml' => $this->xml('<urlset><url><loc>https://example.com/a</loc></url><url><loc>https://example.com/b</loc></url></urlset>'),
        ]);
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/urls/bulk', ['urls' => 'https://example.com/a'])->assertCreated();

        $this->artisan('pins:from-site', ['url' => 'https://example.com/sitemap.xml', '--user' => $user->email])->assertSuccessful();

        Queue::assertPushed(ProcessUrlSubmission::class, 2); // 1 from the bulk call, 1 new page
        $this->assertSame(['https://example.com/b'], UrlBatch::latest('id')->first()->submissions()->pluck('url')->all());
    }
}
