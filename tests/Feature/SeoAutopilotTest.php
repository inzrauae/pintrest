<?php

namespace Tests\Feature;

use App\Models\GeneratedPin;
use App\Models\PinAnalytic;
use App\Models\PinterestAccount;
use App\Models\PinterestBoard;
use App\Models\SeoTopic;
use App\Models\User;
use App\Services\Seo\PinAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SeoAutopilotTest extends TestCase
{
    use RefreshDatabase;

    private function setUpUser(): array
    {
        $user = User::factory()->create();
        $account = PinterestAccount::create([
            'user_id' => $user->id, 'pinterest_user_id' => 'pu_auto', 'username' => 'auto', 'environment' => 'production',
            'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'token_expires_at' => now()->addDays(10),
            'scopes' => 'boards:read,pins:read,pins:write,user_accounts:read,ads:read', 'status' => 'active',
        ]);
        PinterestBoard::create(['pinterest_account_id' => $account->id, 'board_id' => 'b_basil', 'name' => 'Basil Growing Tips']);

        return [$user, $account];
    }

    private function fakeSite(int $pages = 3): void
    {
        $urls = implode('', array_map(fn ($i) => "<url><loc>https://example.com/basil-{$i}</loc></url>", range(1, $pages)));
        Http::fake([
            'https://example.com/sitemap.xml' => fn () => Http::response('<?xml version="1.0"?><urlset>'.$urls.'</urlset>', 200, ['Content-Type' => 'application/xml']),
            'https://example.com/img/*' => fn () => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
            'https://example.com/*' => fn () => Http::response($this->articleHtml(), 200, ['Content-Type' => 'text/html']),
            'https://api.pinterest.com/v5/terms/suggested*' => Http::response(['grow basil indoors']),
            'https://api.pinterest.com/v5/terms/related*' => Http::response(['related_terms_list' => []]),
            'https://api.pinterest.com/v5/trends/*' => Http::response(['trends' => []]),
        ]);
    }

    public function test_topic_board_must_belong_to_the_account(): void
    {
        [$user, $account] = $this->setUpUser();

        $this->actingAs($user)->postJson('/api/seo/topics', ['name' => 'Basil', 'keywords' => ['basil care'], 'pinterest_account_id' => $account->id, 'board_id' => 'someone-elses'])
            ->assertUnprocessable()->assertJsonValidationErrors('board_id');
        $this->actingAs($user)->postJson('/api/seo/topics', ['name' => 'Basil', 'keywords' => ['  ']])
            ->assertUnprocessable()->assertJsonValidationErrors('keywords');
    }

    public function test_run_discovers_new_pages_and_creates_topic_pins_that_are_auto_approved(): void
    {
        $this->fakeSite(3);
        [$user, $account] = $this->setUpUser();
        $topicId = $this->actingAs($user)->postJson('/api/seo/topics', [
            'name' => 'Basil', 'keywords' => ['basil care', 'herb garden'], 'source_url' => 'https://example.com/sitemap.xml',
            'pinterest_account_id' => $account->id, 'board_id' => 'b_basil', 'owns_images' => true, 'auto_approve' => true, 'max_new_per_run' => 2,
        ])->assertCreated()->json('data.id');

        $run = $this->actingAs($user)->postJson("/api/seo/topics/{$topicId}/run")->assertOk()->json('run');

        $this->assertSame(['found' => 3, 'new' => 3, 'queued' => 2], array_intersect_key($run, array_flip(['found', 'new', 'queued'])));
        $pins = GeneratedPin::where('seo_topic_id', $topicId)->get();
        $this->assertCount(2, $pins);
        foreach ($pins as $pin) {
            $this->assertSame(['b_basil', $account->id, 'approved'], [$pin->board_id, $pin->pinterest_account_id, $pin->status]);
            $this->assertTrue($pin->selectedImage->rights_confirmed);
        }
        // The topic's main keyword seeds Pinterest research.
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.pinterest.com/v5/terms/suggested') && $r['term'] === 'basil care');

        // The next run only picks up the page that has no Pin yet.
        $second = $this->actingAs($user)->postJson("/api/seo/topics/{$topicId}/run")->json('run');
        $this->assertSame(1, $second['queued'], json_encode($second));
    }

    public function test_without_auto_approve_or_image_rights_pins_stay_drafts(): void
    {
        $this->fakeSite(1);
        [$user, $account] = $this->setUpUser();
        $topic = SeoTopic::create(['user_id' => $user->id, 'name' => 'Basil', 'keywords' => ['basil care'], 'source_url' => 'https://example.com/sitemap.xml',
            'pinterest_account_id' => $account->id, 'board_id' => 'b_basil', 'auto_approve' => true, 'owns_images' => false]);

        $this->actingAs($user)->postJson("/api/seo/topics/{$topic->id}/run")->assertOk();

        $this->assertSame('draft', GeneratedPin::where('seo_topic_id', $topic->id)->value('status'), 'third-party image rights not confirmed');
        $this->assertDatabaseHas('publication_logs', ['event' => 'pin_auto_approve_skipped', 'user_id' => $user->id]);
    }

    public function test_autopilot_command_only_runs_due_topics(): void
    {
        Queue::fake();
        $this->fakeSite(2);
        [$user] = $this->setUpUser();
        $due = SeoTopic::create(['user_id' => $user->id, 'name' => 'Due', 'keywords' => ['basil'], 'source_url' => 'https://example.com/sitemap.xml']);
        $recent = SeoTopic::create(['user_id' => $user->id, 'name' => 'Recent', 'keywords' => ['basil'], 'source_url' => 'https://example.com/sitemap.xml', 'last_run_at' => now()->subHour()]);
        $off = SeoTopic::create(['user_id' => $user->id, 'name' => 'Off', 'keywords' => ['basil'], 'source_url' => 'https://example.com/sitemap.xml', 'auto_discover' => false]);

        $this->artisan('seo:autopilot')->assertSuccessful();

        $this->assertNotNull($due->fresh()->last_run_at);
        $this->assertSame(2, $due->fresh()->last_run['queued']);
        $this->assertTrue($recent->fresh()->last_run_at->lt(now()->subMinutes(30)));
        $this->assertNull($off->fresh()->last_run_at);
    }

    public function test_topics_are_private(): void
    {
        [$user] = $this->setUpUser();
        $topic = SeoTopic::create(['user_id' => $user->id, 'name' => 'Mine', 'keywords' => ['basil']]);
        $other = User::factory()->create();

        $this->actingAs($other)->getJson('/api/seo/topics')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($other)->putJson("/api/seo/topics/{$topic->id}", ['name' => 'Stolen'])->assertForbidden();
        $this->actingAs($other)->postJson("/api/seo/topics/{$topic->id}/run")->assertForbidden();
        $this->actingAs($other)->deleteJson("/api/seo/topics/{$topic->id}")->assertForbidden();
    }

    public function test_analytics_are_synced_for_production_pins_and_summarised(): void
    {
        [$user, $pin, $account] = $this->publishablePin(['status' => 'published', 'pinterest_pin_id' => '555', 'published_at' => now()->subDays(3)]);
        $topic = SeoTopic::create(['user_id' => $user->id, 'name' => 'Basil', 'keywords' => ['basil']]);
        $pin->update(['seo_topic_id' => $topic->id]);
        $yesterday = now('UTC')->subDay()->toDateString();
        Http::fake(['https://api.pinterest.com/v5/pins/555/analytics*' => Http::response(['all' => [
            'daily_metrics' => [
                ['date' => $yesterday, 'data_status' => 'READY', 'metrics' => ['IMPRESSION' => 200, 'SAVE' => 6, 'PIN_CLICK' => 12, 'OUTBOUND_CLICK' => 8]],
            ],
            'summary_metrics' => ['IMPRESSION' => 200],
        ]])]);

        $this->actingAs($user)->postJson('/api/seo/analytics/sync')->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'pins/555/analytics') && $r['metric_types'] === 'IMPRESSION,SAVE,PIN_CLICK,OUTBOUND_CLICK');
        $this->assertSame(200, PinAnalytic::where('generated_pin_id', $pin->id)->value('impressions'));

        $index = $this->actingAs($user)->getJson('/api/seo/topics')->assertOk();
        $index->assertJsonPath('analytics.totals.impressions', 200)->assertJsonPath('analytics.ctr', 4);
        $index->assertJsonPath('data.0.analytics.totals.outbound_clicks', 8);

        // Sandbox Pins are never sent to the analytics endpoint.
        $account->update(['environment' => 'sandbox']);
        $this->assertFalse(app(PinAnalyticsService::class)->sync($pin->fresh()));
    }
}
