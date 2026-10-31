<?php

namespace Tests\Feature;

use App\Models\PinterestAccount;
use App\Models\User;
use App\Services\Pinterest\PinterestApiException;
use App\Services\Pinterest\PinterestClient;
use App\Services\Pinterest\PinterestRateLimiter;
use App\Services\Seo\KeywordResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PinterestRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $environment = 'production'): PinterestAccount
    {
        return PinterestAccount::create([
            'user_id' => User::factory()->create()->id, 'pinterest_user_id' => 'pu_'.$environment, 'username' => 'rl',
            'environment' => $environment, 'access_token' => 'access-1', 'refresh_token' => 'r', 'token_expires_at' => now()->addDays(10),
            'scopes' => 'boards:read,pins:read,pins:write,user_accounts:read,ads:read', 'status' => 'active',
        ]);
    }

    public function test_categories_follow_the_pinterest_reference(): void
    {
        $this->assertSame('trends_read', PinterestRateLimiter::category('get', 'trends/keywords/US/top/monthly'));
        $this->assertSame('ads_read', PinterestRateLimiter::category('get', 'terms/suggested'));
        $this->assertSame('org_analytics', PinterestRateLimiter::category('get', 'pins/123/analytics'));
        $this->assertSame('org_write', PinterestRateLimiter::category('post', 'pins'));
        $this->assertSame('org_read', PinterestRateLimiter::category('get', 'boards'));
    }

    public function test_trial_daily_category_limit_stops_requests_before_pinterest_does(): void
    {
        config(['pinbot.pinterest_limits.access_level' => 'trial', 'pinbot.pinterest_limits.trial.org_read' => 2]);
        $account = $this->account();
        Http::fake(['https://api.pinterest.com/v5/boards*' => Http::response(['items' => []])]);
        $client = app(PinterestClient::class);

        $client->get($account, 'boards');
        $client->get($account, 'boards');
        try {
            $client->get($account, 'boards');
            $this->fail('Third request should be refused by the budget.');
        } catch (PinterestApiException $e) {
            $this->assertSame(429, $e->status);
            $this->assertTrue($e->retryable);
            $this->assertGreaterThan(0, $e->retryAfter);
            $this->assertStringContainsString('daily org read limit reached', $e->getMessage());
        }

        Http::assertSentCount(2);
    }

    public function test_low_priority_work_leaves_room_for_publishing(): void
    {
        config(['pinbot.pinterest_limits.access_level' => 'trial', 'pinbot.pinterest_limits.trial.all' => 10, 'pinbot.pinterest_limits.low_priority_share' => 0.7]);
        $account = $this->account();
        $limiter = app(PinterestRateLimiter::class);

        for ($i = 0; $i < 7; $i++) {
            $limiter->reserve($account, 'trends_read', PinterestRateLimiter::PRIORITY_LOW);
        }
        try {
            $limiter->reserve($account, 'trends_read', PinterestRateLimiter::PRIORITY_LOW);
            $this->fail('Low priority must stop at 70%.');
        } catch (PinterestApiException $e) {
            $this->assertStringContainsString('kept for publishing', $e->getMessage());
        }

        // Publishing (normal priority) can still use the remaining 30%.
        for ($i = 0; $i < 3; $i++) {
            $limiter->reserve($account, 'org_write');
        }
        $this->assertSame(10, $limiter->used($account, 'all'));
        $this->expectException(PinterestApiException::class);
        $limiter->reserve($account, 'org_write');
    }

    public function test_trial_counts_per_app_and_environments_separately(): void
    {
        config(['pinbot.pinterest_limits.access_level' => 'trial']);
        $limiter = app(PinterestRateLimiter::class);
        $a = $this->account('production');
        $b = PinterestAccount::create($a->only(['user_id', 'environment', 'access_token', 'status']) + ['pinterest_user_id' => 'other', 'username' => 'b']);
        $sandbox = $this->account('sandbox');

        $limiter->reserve($a, 'org_read');
        $limiter->reserve($b, 'org_read');

        $this->assertSame(2, $limiter->used($a, 'all'), 'trial limits are shared by the whole app');
        $this->assertSame(0, $limiter->used($sandbox, 'all'));
    }

    public function test_standard_access_counts_per_account_per_minute(): void
    {
        config(['pinbot.pinterest_limits.access_level' => 'standard', 'pinbot.pinterest_limits.standard.trends_read' => 1]);
        $limiter = app(PinterestRateLimiter::class);
        $a = $this->account();
        $b = PinterestAccount::create($a->only(['user_id', 'environment', 'access_token', 'status']) + ['pinterest_user_id' => 'other', 'username' => 'b']);

        $limiter->reserve($a, 'trends_read');
        $limiter->reserve($b, 'trends_read'); // separate per-user budget

        $this->travel(61)->seconds();
        $limiter->reserve($a, 'trends_read'); // new minute
        $this->expectException(PinterestApiException::class);
        $limiter->reserve($a, 'trends_read');
    }

    public function test_research_degrades_quietly_when_out_of_budget(): void
    {
        config(['pinbot.pinterest_limits.access_level' => 'trial', 'pinbot.pinterest_limits.trial.ads_read' => 1]);
        $account = $this->account();
        Http::fake([
            'https://api.pinterest.com/v5/terms/*' => Http::response(['grow basil indoors']),
            'https://api.pinterest.com/v5/trends/*' => Http::response(['trends' => []]),
        ]);
        $research = app(KeywordResearchService::class);

        $research->research($account->user, ['grow basil', 'basil care'], 'grow basil indoors');

        // ads_read cap is 1 × 70% → floor → at least 1 call, then research stops asking without marking the endpoint broken.
        Http::assertSentCount(2); // 1 suggested + 1 trends
        $this->assertSame('ok', $research->status($account->user)['terms']);
    }

    public function test_exhausted_window_from_pinterest_headers_pauses_the_account(): void
    {
        $account = $this->account();
        Http::fake(['https://api.pinterest.com/v5/boards*' => Http::response(['items' => []], 200, ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '30'])]);
        $client = app(PinterestClient::class);

        $client->get($account, 'boards');

        $this->expectExceptionMessage('Pinterest rate limit reached');
        $client->get($account, 'boards');
    }

    public function test_publish_limit_setting_cannot_exceed_pinterest_limit(): void
    {
        config(['pinbot.pinterest_limits.access_level' => 'trial']);
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/settings/meta')->assertJsonPath('data.limits.daily_publish_limit_max', 300);
        $this->actingAs($user)->putJson('/api/settings/preferences', ['daily_publish_limit' => 301])
            ->assertUnprocessable()->assertJsonValidationErrors('daily_publish_limit');
        $this->actingAs($user)->putJson('/api/settings/preferences', ['daily_publish_limit' => 300])->assertOk();

        config(['pinbot.pinterest_limits.access_level' => 'standard']);
        $this->actingAs($user)->putJson('/api/settings/preferences', ['daily_publish_limit' => 500])->assertOk();
        $this->actingAs($user)->putJson('/api/settings/preferences', ['daily_publish_limit' => 501])->assertUnprocessable();
    }

    public function test_a_value_saved_before_the_cap_is_treated_as_the_cap(): void
    {
        config(['pinbot.pinterest_limits.access_level' => 'trial']);
        $user = User::factory()->create();
        $user->preferences()->update(['daily_publish_limit' => 500]); // saved under the old 500 maximum

        $this->assertSame(300, app(\App\Services\UsageLimiter::class)->dailyPublishLimit($user));
        $this->actingAs($user)->getJson('/api/dashboard')->assertJsonPath('data.limits.daily_publish_limit', 300);
    }

    public function test_dashboard_reports_usage(): void
    {
        config(['pinbot.pinterest_limits.access_level' => 'trial']);
        $account = $this->account();
        app(PinterestRateLimiter::class)->reserve($account, 'org_write');

        $usage = $this->actingAs($account->user)->getJson('/api/seo/overview')->assertOk()->json('data.api_usage.0');

        $this->assertSame(['production', 'trial', 'day'], [$usage['environment'], $usage['level'], $usage['window']]);
        $this->assertContains(['bucket' => 'org_write', 'used' => 1, 'limit' => 300], $usage['buckets']);
    }
}
