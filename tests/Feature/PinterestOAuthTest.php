<?php

namespace Tests\Feature;

use App\Models\PinterestAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PinterestOAuthTest extends TestCase
{
    use RefreshDatabase;

    private function startFlow(User $user, string $env = 'production'): string
    {
        $url = $this->actingAs($user)->postJson('/api/pinterest/connect', ['environment' => $env])->assertOk()->json('authorize_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://www.pinterest.com/oauth/', $url);
        $this->assertSame('code', $query['response_type']);
        $this->assertStringContainsString('pins:write', $query['scope']);

        return $query['state'];
    }

    private function fakePinterest(string $host = 'https://api.pinterest.com'): void
    {
        Http::fake([
            "{$host}/v5/oauth/token" => Http::response([
                'access_token' => 'pina_access', 'refresh_token' => 'pinr_refresh', 'expires_in' => 2592000,
                'refresh_token_expires_in' => 31536000, 'scope' => 'boards:read,pins:read,pins:write,user_accounts:read',
            ]),
            "{$host}/v5/user_account" => Http::response(['id' => '123', 'username' => 'gardener', 'account_type' => 'BUSINESS']),
            "{$host}/v5/boards*" => Http::response(['items' => [['id' => 'b1', 'name' => 'Herbs', 'privacy' => 'PUBLIC', 'pin_count' => 3]], 'bookmark' => null]),
        ]);
    }

    public function test_full_oauth_flow_stores_encrypted_tokens_and_syncs_boards(): void
    {
        $this->fakePinterest();
        $user = User::factory()->create();
        $state = $this->startFlow($user);

        $this->actingAs($user)->get("/pinterest/callback?code=abc&state={$state}")->assertRedirect('/accounts?pinterest=connected');

        $account = PinterestAccount::first();
        $this->assertSame('gardener', $account->username);
        $this->assertSame('pina_access', $account->access_token);
        $this->assertNotSame('pina_access', DB::table('pinterest_accounts')->value('access_token'), 'token is encrypted at rest');
        $this->assertSame(['Herbs'], $account->boards()->pluck('name')->all());

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/oauth/token')
            && $r['grant_type'] === 'authorization_code'
            && $r['continuous_refresh'] === 'true'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('test-client:test-secret')));

        // Tokens never reach the frontend.
        $this->actingAs($user)->getJson('/api/pinterest/accounts')
            ->assertOk()
            ->assertJsonMissingPath('data.0.access_token')
            ->assertJsonMissingPath('data.0.refresh_token');
    }

    public function test_sandbox_flow_uses_sandbox_host(): void
    {
        $this->fakePinterest('https://api-sandbox.pinterest.com');
        $user = User::factory()->create();
        $state = $this->startFlow($user, 'sandbox');

        $this->get("/pinterest/callback?code=abc&state={$state}")->assertRedirect('/accounts?pinterest=connected');
        $this->assertSame('sandbox', PinterestAccount::first()->environment);
    }

    public function test_invalid_or_reused_state_is_rejected(): void
    {
        $this->fakePinterest();
        $user = User::factory()->create();
        $state = $this->startFlow($user);

        $this->get('/pinterest/callback?code=abc&state=forged')->assertRedirectContains('pinterest=error');
        $this->get("/pinterest/callback?code=abc&state={$state}")->assertRedirect('/accounts?pinterest=connected');
        $this->get("/pinterest/callback?code=abc&state={$state}")->assertRedirectContains('pinterest=error');
        $this->assertSame(1, PinterestAccount::count());
    }

    public function test_state_cannot_be_redeemed_by_another_signed_in_user(): void
    {
        $this->fakePinterest();
        $victim = User::factory()->create();
        $state = $this->startFlow($victim);

        $this->actingAs(User::factory()->create())->get("/pinterest/callback?code=abc&state={$state}")->assertRedirectContains('pinterest=error');
        $this->assertSame(0, PinterestAccount::count());
    }

    public function test_token_exchange_failure_is_logged_with_details(): void
    {
        Http::fake(['https://api.pinterest.com/v5/oauth/token' => Http::response(['code' => 1, 'message' => 'Invalid authorization code'], 400)]);
        $user = User::factory()->create();
        $state = $this->startFlow($user);

        $this->get("/pinterest/callback?code=bad&state={$state}")->assertRedirectContains('pinterest=error');
        $this->assertDatabaseHas('publication_logs', ['event' => 'pinterest_oauth_failed', 'level' => 'error', 'http_status' => 400]);
    }

    public function test_user_denying_consent_is_handled(): void
    {
        $user = User::factory()->create();
        $state = $this->startFlow($user);

        $this->get("/pinterest/callback?error=access_denied&state={$state}")->assertRedirectContains('Authorization+was+cancelled');
    }

    public function test_connect_requires_configuration(): void
    {
        config(['services.pinterest.client_id' => null]);
        $this->actingAs(User::factory()->create())->postJson('/api/pinterest/connect')->assertStatus(503);
    }

    public function test_board_retrieval_paginates_and_prunes_removed_boards(): void
    {
        [$user, , $account] = $this->publishablePin();
        Http::fake([
            'https://api.pinterest.com/v5/boards?page_size=100' => Http::response(['items' => [['id' => 'b1', 'name' => 'A']], 'bookmark' => 'next']),
            'https://api.pinterest.com/v5/boards?page_size=100&bookmark=next' => Http::response(['items' => [['id' => 'b2', 'name' => 'B']], 'bookmark' => null]),
        ]);

        $this->actingAs($user)->getJson('/api/pinterest/boards?refresh=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertSame(['b1', 'b2'], $account->boards()->orderBy('board_id')->pluck('board_id')->all(), 'stale board b_1 removed');
    }

    public function test_disconnect_deletes_tokens(): void
    {
        [$user, , $account] = $this->publishablePin();

        $this->actingAs($user)->deleteJson("/api/pinterest/accounts/{$account->id}")->assertOk();
        $this->assertDatabaseMissing('pinterest_accounts', ['id' => $account->id]);
    }
}
