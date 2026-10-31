<?php

namespace Tests\Feature;

use App\Models\GeneratedPin;
use App\Models\PinterestAccount;
use App\Models\PinterestBoard;
use App\Models\User;
use App\Services\Seo\SeoScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    private function connectedUser(string $scopes = 'boards:read,pins:read,pins:write,user_accounts:read,ads:read'): array
    {
        $user = User::factory()->create();
        $account = PinterestAccount::create([
            'user_id' => $user->id,
            'pinterest_user_id' => 'pu_seo',
            'username' => 'seo',
            'environment' => 'production',
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->addDays(10),
            'scopes' => $scopes,
            'status' => 'active',
        ]);

        return [$user, $account];
    }

    private function fakeSite(array $pinterest = []): void
    {
        Http::fake($pinterest + [
            'https://api.pinterest.com/v5/terms/suggested*' => Http::response(['grow basil indoors', 'grow basil from seed']),
            'https://api.pinterest.com/v5/terms/related*' => Http::response(['id' => 'grow basil', 'related_term_count' => 2, 'related_terms_list' => [
                ['term' => 'grow basil', 'related_terms' => ['basil indoor garden', 'yoga pose ideas']],
            ]]),
            'https://api.pinterest.com/v5/trends/keywords/*' => Http::response(['trends' => [
                ['keyword' => 'basil indoor garden', 'pct_growth_wow' => 10, 'pct_growth_mom' => 120, 'pct_growth_yoy' => 300, 'time_series' => ['2026-10-01' => 40, '2026-10-08' => 80]],
            ]]),
            'https://example.com/img/*' => fn () => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
            'https://example.com/*' => fn () => Http::response($this->articleHtml(), 200, ['Content-Type' => 'text/html']),
        ]);
    }

    public function test_generated_pin_uses_researched_pinterest_keywords(): void
    {
        $this->fakeSite();
        [$user] = $this->connectedUser();

        $pinId = $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'https://example.com/basil-guide'])->assertCreated()->json('pin.id');
        $pin = GeneratedPin::with('keywords')->find($pinId);

        $researched = array_column($pin->researched_keywords, 'keyword');
        $this->assertContains('grow basil indoors', $researched);
        $this->assertContains('basil indoor garden', $researched);
        $this->assertNotContains('yoga pose ideas', $researched, 'off-topic suggestions are filtered out');
        $this->assertSame('basil indoor garden', $pin->researched_keywords[0]['keyword'], 'trending keywords rank first');
        $this->assertSame(120, $pin->researched_keywords[0]['growth']);
        $this->assertContains('grow basil indoors', $pin->keywordList());
        $this->assertNotNull($pin->seo_score);
        $this->assertSame('grow basil indoors', $pin->focus_keyword, 'the researched term in the title becomes the focus keyword');

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.pinterest.com/v5/terms/suggested') && $r->hasHeader('Authorization', 'Bearer access-1'));
    }

    public function test_refused_research_does_not_break_generation_and_is_not_retried_immediately(): void
    {
        $refused = Http::response(['code' => 3, 'message' => 'Authentication failed.'], 403);
        $this->fakeSite([
            'https://api.pinterest.com/v5/terms/*' => $refused,
            'https://api.pinterest.com/v5/trends/*' => $refused,
        ]);
        [$user] = $this->connectedUser();

        $pinId = $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'https://example.com/basil-guide'])->assertCreated()->json('pin.id');

        $this->assertNull(GeneratedPin::find($pinId)->researched_keywords, 'research marked as not run, so scoring does not penalise it');
        $this->assertSame('unavailable', $this->actingAs($user)->getJson('/api/seo/overview')->json('data.research.terms'));

        Http::fake(); // any further Pinterest research call would now be recorded
        $this->actingAs($user)->getJson('/api/seo/keywords?term=basil')->assertOk();
        Http::assertNothingSent();
    }

    public function test_each_source_uses_the_best_account_and_single_words_are_dropped(): void
    {
        // Production without ads:read + a sandbox default account (the common "connected before ads:read" setup).
        [$user, $production] = $this->connectedUser('boards:read,pins:read,pins:write,user_accounts:read');
        $sandbox = PinterestAccount::create([
            'user_id' => $user->id, 'pinterest_user_id' => 'pu_sb', 'username' => 'seo', 'environment' => 'sandbox',
            'access_token' => 'sandbox-1', 'refresh_token' => 'r', 'token_expires_at' => now()->addDays(10), 'status' => 'active',
        ]);
        $user->preferences()->update(['default_pinterest_account_id' => $sandbox->id]);
        Http::fake([
            'https://api-sandbox.pinterest.com/v5/terms/suggested*' => Http::response(['grow basil', 'basil']),
            'https://api-sandbox.pinterest.com/v5/terms/related*' => Http::response(['related_terms_list' => []]),
            'https://api.pinterest.com/v5/trends/*' => Http::response(['trends' => []]),
        ]);

        $rows = app(\App\Services\Seo\KeywordResearchService::class)->research($user, ['grow basil'], 'grow basil indoors');

        $this->assertSame(['grow basil'], array_column($rows, 'keyword'), 'the one-word "basil" is too broad');
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api-sandbox.pinterest.com/v5/terms/suggested') && $r->hasHeader('Authorization', 'Bearer sandbox-1'));
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.pinterest.com/v5/trends/') && $r->hasHeader('Authorization', 'Bearer access-1'));

        $status = $this->actingAs($user)->getJson('/api/seo/overview')->json('data.research');
        $this->assertSame(['sandbox', 'production'], [$status['terms_environment'], $status['trends_environment']]);

        // After reconnecting production with ads:read, terms move to production automatically.
        $production->update(['scopes' => 'boards:read,pins:read,pins:write,user_accounts:read,ads:read']);
        $this->assertSame('production', $this->actingAs($user)->getJson('/api/seo/overview')->json('data.research.terms_environment'));
    }

    public function test_research_is_skipped_when_disabled(): void
    {
        $this->fakeSite();
        [$user] = $this->connectedUser();
        $user->preferences()->update(['keyword_research' => false]);

        $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'https://example.com/basil-guide'])->assertCreated();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.pinterest.com'));
    }

    public function test_score_rewards_keywords_in_title_description_alt_and_board(): void
    {
        [$user, $account] = $this->connectedUser();
        PinterestBoard::create(['pinterest_account_id' => $account->id, 'board_id' => 'b1', 'name' => 'Indoor Herb Garden']);
        $base = ['user_id' => $user->id, 'destination_url' => 'https://example.com/x', 'status' => 'draft', 'pinterest_account_id' => $account->id];

        $good = GeneratedPin::create($base + [
            'title' => 'Grow Basil Indoors: An Easy Windowsill Herb Garden Guide',
            'description' => 'Learn how to grow basil indoors on a sunny windowsill. This herb garden guide covers basil plant care, watering, pruning and harvesting so your indoor garden keeps producing fresh leaves all year.',
            'alt_text' => 'Potted basil plant growing indoors on a windowsill herb garden',
            'board_id' => 'b1',
            'researched_keywords' => [['keyword' => 'grow basil indoors', 'sources' => ['suggested'], 'growth' => null]],
        ]);
        $good->syncKeywords(['herb garden', 'basil plant care', 'indoor garden']);

        $bad = GeneratedPin::create($base + ['title' => 'Read this', 'description' => 'Click here for more.']);
        $bad->syncKeywords(['basil']);

        $scorer = app(SeoScorer::class);
        $goodResult = $scorer->evaluate($good->fresh('keywords'));
        $badResult = $scorer->evaluate($bad->fresh('keywords'));

        $this->assertSame(100, $goodResult['score'], json_encode(array_filter($goodResult['checks'], fn ($c) => ! $c['passed'])));
        $this->assertSame('grow basil indoors', $goodResult['focus_keyword']);
        $this->assertLessThan(20, $badResult['score']);
        $this->assertNotEmpty(array_filter($badResult['checks'], fn ($c) => ! $c['passed'] && $c['tip'] !== ''));
    }

    public function test_dashboard_endpoints(): void
    {
        $this->fakeSite();
        [$user] = $this->connectedUser();
        $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'https://example.com/basil-guide'])->assertCreated();

        $overview = $this->actingAs($user)->getJson('/api/seo/overview')->assertOk()->json('data');
        $this->assertSame(1, $overview['stats']['pins']);
        $this->assertSame(1, $overview['stats']['with_research']);
        $this->assertSame('ok', $overview['research']['terms']);
        $this->assertContains('Basil Indoor Garden', $overview['boards']['suggestions']);

        $this->actingAs($user)->getJson('/api/seo/pins')->assertOk()->assertJsonPath('total', 1)->assertJsonStructure(['data' => [['id', 'title', 'seo' => ['score', 'focus_keyword', 'checks']]]]);

        $this->actingAs($user)->getJson('/api/seo/keywords?term=grow basil')->assertOk()
            ->assertJsonPath('data.suggested.0', 'grow basil indoors')
            ->assertJsonPath('data.related', ['basil indoor garden', 'yoga pose ideas']);

        $this->actingAs($user)->getJson('/api/seo/trends?region=GB%2BIE&type=growing')->assertOk()
            ->assertJsonPath('data.trends.0.keyword', 'basil indoor garden')
            ->assertJsonPath('data.trends.0.pct_growth_mom', 120);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.pinterest.com/v5/trends/keywords/GB%2BIE/top/growing'));

        $this->actingAs($user)->getJson('/api/seo/trends?region=LK')->assertUnprocessable();
    }

    public function test_optimize_rewrites_copy_and_is_owner_only(): void
    {
        $this->fakeSite();
        [$user] = $this->connectedUser();
        $pinId = $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'https://example.com/basil-guide'])->assertCreated()->json('pin.id');

        $this->actingAs(User::factory()->create())->postJson("/api/seo/pins/{$pinId}/optimize")->assertForbidden();

        $this->actingAs($user)->postJson("/api/seo/pins/{$pinId}/optimize")->assertOk()->assertJsonStructure(['data' => ['seo' => ['score']]]);
        $this->assertNotNull(GeneratedPin::find($pinId)->seo_score);
    }
}
