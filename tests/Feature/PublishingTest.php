<?php

namespace Tests\Feature;

use App\Jobs\PublishPinJob;
use App\Models\GeneratedPin;
use App\Models\PublishingJob;
use App\Services\Pinterest\PinterestApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishes_pin_through_official_api(): void
    {
        [$user, $pin] = $this->publishablePin();
        $pin->syncKeywords(['#basil'], 'hashtag');
        Http::fake(['https://api.pinterest.com/v5/pins' => Http::response(['id' => '987654'], 201)]);

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")->assertOk();

        $pin->refresh();
        $this->assertSame('published', $pin->status);
        $this->assertSame('987654', $pin->pinterest_pin_id);
        $this->assertNotNull($pin->published_at);
        $this->assertSame('succeeded', PublishingJob::first()->status);

        Http::assertSent(function ($r) {
            return $r->url() === 'https://api.pinterest.com/v5/pins'
                && $r->hasHeader('Authorization', 'Bearer access-1')
                && $r['board_id'] === 'b_1'
                && $r['link'] === 'https://example.com/basil-guide'
                && $r['media_source']['source_type'] === 'image_base64'
                && str_ends_with($r['description'], '#basil');
        });
    }

    public function test_a_pin_is_never_published_twice(): void
    {
        [$user, $pin] = $this->publishablePin();
        Http::fake(['https://api.pinterest.com/v5/pins' => Http::response(['id' => '1'], 201)]);

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")->assertOk();
        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")->assertUnprocessable();

        Http::assertSentCount(1);
    }

    public function test_duplicate_destination_on_same_board_requires_confirmation(): void
    {
        [$user, $pin] = $this->publishablePin();
        Http::fake(['https://api.pinterest.com/v5/pins' => Http::response(['id' => '1'], 201)]);
        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")->assertOk();

        $copy = GeneratedPin::create($pin->only(['user_id', 'pinterest_account_id', 'board_id', 'title', 'description', 'destination_url']) + ['status' => 'draft']);
        $copy->images()->create($pin->images()->first()->only(['source', 'original_path', 'path', 'mime', 'width', 'height', 'size_bytes', 'status']) + ['is_selected' => true, 'rights_confirmed' => true]);

        $this->actingAs($user)->postJson("/api/pins/{$copy->id}/publish")
            ->assertUnprocessable()
            ->assertJsonFragment(['A Pin for this destination URL was already published to the same board. Confirm to publish a duplicate.']);

        $this->actingAs($user)->postJson("/api/pins/{$copy->id}/publish", ['allow_duplicate' => true])->assertOk();
        $this->assertSame('published', $copy->fresh()->status);
    }

    public function test_extracted_image_needs_rights_confirmation(): void
    {
        [$user, $pin] = $this->publishablePin([], 'extracted');
        Http::fake(['https://api.pinterest.com/v5/pins' => Http::response(['id' => '1'], 201)]);

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")
            ->assertUnprocessable()
            ->assertJsonFragment(['Confirm you have the right to use the selected image from the source page.']);

        $image = $pin->images()->first();
        $this->actingAs($user)->patchJson("/api/pin-images/{$image->id}", ['rights_confirmed' => true])->assertOk();
        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")->assertOk();
    }

    public function test_expired_token_is_refreshed_and_request_retried(): void
    {
        [$user, $pin, $account] = $this->publishablePin();
        Http::fake([
            'https://api.pinterest.com/v5/oauth/token' => Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600]),
            'https://api.pinterest.com/v5/pins' => Http::sequence()
                ->push(['code' => 2, 'message' => 'Authentication failed.'], 401)
                ->push(['id' => '55'], 201),
        ]);

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")->assertOk();

        $this->assertSame('published', $pin->fresh()->status);
        $account->refresh();
        $this->assertSame('access-2', $account->access_token);
        $this->assertSame('refresh-2', $account->refresh_token);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/oauth/token') && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'refresh-1');
    }

    public function test_revoked_authorization_marks_account_expired_and_fails_pin(): void
    {
        [$user, $pin, $account] = $this->publishablePin();
        Http::fake([
            'https://api.pinterest.com/v5/oauth/token' => Http::response(['code' => 1, 'message' => 'invalid_grant'], 400),
            'https://api.pinterest.com/v5/pins' => Http::response(['code' => 2, 'message' => 'Authentication failed.'], 401),
        ]);

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")->assertUnprocessable();

        $this->assertSame('expired', $account->fresh()->status);
        $pin->refresh();
        $this->assertSame('failed', $pin->status);
        $this->assertStringContainsString('reconnect', $pin->error_message);
    }

    public function test_validation_error_shows_simple_message_and_logs_raw_error(): void
    {
        [$user, $pin] = $this->publishablePin();
        Http::fake(['https://api.pinterest.com/v5/pins' => Http::response(['code' => 1, 'message' => 'Invalid parameters: link is not a valid URL'], 400)]);

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")->assertUnprocessable();

        $pin->refresh();
        $this->assertSame('failed', $pin->status);
        $this->assertSame('Pinterest rejected the Pin: Invalid parameters: link is not a valid URL', $pin->error_message);

        $log = $pin->logs()->where('event', 'publish_error')->first();
        $this->assertSame(400, $log->http_status);
        $this->assertSame('Invalid parameters: link is not a valid URL', $log->details['pinterest_error']['message']);
        $this->assertSame(1, $pin->publishingJobs()->first()->attempts);
    }

    public function test_trial_access_refuses_production_publishing_before_calling_pinterest(): void
    {
        config(['pinbot.pinterest_limits.access_level' => 'trial']);
        [$user, $pin] = $this->publishablePin();
        Http::fake();

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")
            ->assertUnprocessable()
            ->assertJsonFragment([PinterestApiException::TRIAL_PRODUCTION_MESSAGE]);
        Http::assertNothingSent();
        $this->assertSame('draft', $pin->fresh()->status);
    }

    public function test_on_trial_access_a_production_pin_can_be_published_with_the_sandbox_account(): void
    {
        config(['pinbot.pinterest_limits.access_level' => 'trial']);
        [$user, $pin] = $this->publishablePin(); // production account, board "Gardening"
        $sandbox = \App\Models\PinterestAccount::create([
            'user_id' => $user->id, 'pinterest_user_id' => 'pu_sb', 'username' => 'tester', 'environment' => 'sandbox',
            'access_token' => 'sandbox-token', 'refresh_token' => 'r', 'token_expires_at' => now()->addDays(10), 'status' => 'active',
        ]);
        \App\Models\PinterestBoard::create(['pinterest_account_id' => $sandbox->id, 'board_id' => 'sb_other', 'name' => 'Other']);
        \App\Models\PinterestBoard::create(['pinterest_account_id' => $sandbox->id, 'board_id' => 'sb_garden', 'name' => 'gardening']);
        Http::fake(['https://api-sandbox.pinterest.com/v5/pins' => Http::response(['id' => 'sb-1'], 201)]);

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish", ['use_sandbox' => true])->assertOk()->assertJsonPath('data.status', 'published');

        $pin->refresh();
        $this->assertSame([$sandbox->id, 'sb_garden'], [$pin->pinterest_account_id, $pin->board_id], 'same-named sandbox board');
        Http::assertSent(fn ($r) => $r->url() === 'https://api-sandbox.pinterest.com/v5/pins' && $r->hasHeader('Authorization', 'Bearer sandbox-token'));
    }

    public function test_on_trial_access_plain_publish_automatically_uses_the_sandbox_account(): void
    {
        config(['pinbot.pinterest_limits.access_level' => 'trial']);
        [$user, $pin] = $this->publishablePin();
        $sandbox = \App\Models\PinterestAccount::create([
            'user_id' => $user->id, 'pinterest_user_id' => 'pu_sb', 'username' => 'tester', 'environment' => 'sandbox',
            'access_token' => 'sandbox-token', 'refresh_token' => 'r', 'token_expires_at' => now()->addDays(10), 'status' => 'active',
        ]);
        \App\Models\PinterestBoard::create(['pinterest_account_id' => $sandbox->id, 'board_id' => 'sb_1', 'name' => 'PinBot sandbox test']);
        Http::fake(['https://api-sandbox.pinterest.com/v5/pins' => Http::response(['id' => 'sb-2'], 201)]);

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish") // no use_sandbox flag (e.g. an old browser tab)
            ->assertOk()
            ->assertJsonPath('message', 'Published to your sandbox account (visible only to you while the app has Trial access).');

        $this->assertSame([$sandbox->id, 'sb_1', 'published'], [$pin->fresh()->pinterest_account_id, $pin->fresh()->board_id, $pin->fresh()->status]);
        Http::assertNotSent(fn ($r) => str_starts_with($r->url(), 'https://api.pinterest.com/'));
    }

    public function test_moving_to_sandbox_without_a_sandbox_account_explains_what_to_do(): void
    {
        config(['pinbot.pinterest_limits.access_level' => 'trial']);
        [$user, $pin] = $this->publishablePin();

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish", ['use_sandbox' => true])
            ->assertUnprocessable()
            ->assertJsonFragment(['Connect a sandbox account on the Pinterest Accounts page ("Connect sandbox") to publish while your app has Trial access.']);
    }

    public function test_pinterest_trial_error_code_is_explained(): void
    {
        $e = PinterestApiException::fromResponse(403, ['code' => 29, 'message' => 'Apps with Trial access may not create Pins in production']);

        $this->assertSame(PinterestApiException::TRIAL_PRODUCTION_MESSAGE, $e->getMessage());
        $this->assertFalse($e->retryable);
    }

    public function test_one_click_publish_does_not_wait_for_the_queue_worker(): void
    {
        Queue::fake(); // nothing queued would ever run
        [$user, $pin] = $this->publishablePin();
        Http::fake(['https://api.pinterest.com/v5/pins' => Http::response(['id' => '111'], 201)]);

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")
            ->assertOk()
            ->assertJsonPath('message', 'Published to Pinterest.')
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.pinterest_url', 'https://www.pinterest.com/pin/111/');
        Queue::assertNothingPushed();
    }

    public function test_temporary_failure_on_one_click_publish_is_handed_to_the_worker(): void
    {
        Queue::fake();
        [$user, $pin] = $this->publishablePin();
        Http::fake(['https://api.pinterest.com/v5/pins' => Http::response(['code' => 8, 'message' => 'Rate limit'], 429, ['Retry-After' => '120'])]);

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")->assertStatus(202);

        Queue::assertPushed(PublishPinJob::class, 1);
        $this->assertSame('publishing', $pin->fresh()->status);
    }

    public function test_rate_limit_is_retried_with_backoff_not_failed(): void
    {
        [$user, $pin] = $this->publishablePin();
        Http::fake(['https://api.pinterest.com/v5/pins' => Http::response(['code' => 8, 'message' => 'Rate limit'], 429, ['Retry-After' => '120'])]);

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")->assertStatus(202);

        $job = PublishingJob::first();
        $this->assertSame('queued', $job->status, 'released back to the queue for retry');
        $this->assertTrue($job->next_attempt_at->between(now()->addSeconds(100), now()->addSeconds(140)));
        $this->assertSame('publishing', $pin->fresh()->status);
    }

    public function test_preflight_reports_missing_board_and_image(): void
    {
        [$user, $pin] = $this->publishablePin(['board_id' => null]);
        $pin->images()->update(['is_selected' => false]);

        $this->actingAs($user)->getJson("/api/pins/{$pin->id}")
            ->assertOk()
            ->assertJsonFragment(['Select a destination board.'])
            ->assertJsonFragment(['Select a ready image for the Pin.']);
    }

    public function test_daily_publish_limit_is_enforced(): void
    {
        [$user, $pin] = $this->publishablePin();
        $user->preferences()->update(['daily_publish_limit' => 1]);
        GeneratedPin::create(['user_id' => $user->id, 'destination_url' => 'https://example.com/other', 'status' => 'published', 'published_at' => now()]);

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")
            ->assertUnprocessable()
            ->assertJsonFragment(['You have published 1 of your 1 Pins for today. This is your own limit in User Settings → Limits (you can raise it up to 500); it resets at midnight in your timezone. Scheduled Pins wait until then.']);

        // The SEO dashboard shows it next to Pinterest's limits.
        $this->actingAs($user)->getJson('/api/seo/overview')
            ->assertJsonPath('data.own_limits.published_today', 1)
            ->assertJsonPath('data.own_limits.daily_publish_limit', 1);
    }
}
