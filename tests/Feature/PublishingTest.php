<?php

namespace Tests\Feature;

use App\Models\GeneratedPin;
use App\Models\PublishingJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishes_pin_through_official_api(): void
    {
        [$user, $pin] = $this->publishablePin();
        $pin->syncKeywords(['#basil'], 'hashtag');
        Http::fake(['https://api.pinterest.com/v5/pins' => Http::response(['id' => '987654'], 201)]);

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")->assertStatus(202);

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

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")->assertStatus(202);
        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")->assertUnprocessable();

        Http::assertSentCount(1);
    }

    public function test_duplicate_destination_on_same_board_requires_confirmation(): void
    {
        [$user, $pin] = $this->publishablePin();
        Http::fake(['https://api.pinterest.com/v5/pins' => Http::response(['id' => '1'], 201)]);
        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")->assertStatus(202);

        $copy = GeneratedPin::create($pin->only(['user_id', 'pinterest_account_id', 'board_id', 'title', 'description', 'destination_url']) + ['status' => 'draft']);
        $copy->images()->create($pin->images()->first()->only(['source', 'original_path', 'path', 'mime', 'width', 'height', 'size_bytes', 'status']) + ['is_selected' => true, 'rights_confirmed' => true]);

        $this->actingAs($user)->postJson("/api/pins/{$copy->id}/publish")
            ->assertUnprocessable()
            ->assertJsonFragment(['A Pin for this destination URL was already published to the same board. Confirm to publish a duplicate.']);

        $this->actingAs($user)->postJson("/api/pins/{$copy->id}/publish", ['allow_duplicate' => true])->assertStatus(202);
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
        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")->assertStatus(202);
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

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")->assertStatus(202);

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

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")->assertStatus(202);

        $this->assertSame('expired', $account->fresh()->status);
        $pin->refresh();
        $this->assertSame('failed', $pin->status);
        $this->assertStringContainsString('reconnect', $pin->error_message);
    }

    public function test_validation_error_shows_simple_message_and_logs_raw_error(): void
    {
        [$user, $pin] = $this->publishablePin();
        Http::fake(['https://api.pinterest.com/v5/pins' => Http::response(['code' => 1, 'message' => 'Invalid parameters: link is not a valid URL'], 400)]);

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/publish")->assertStatus(202);

        $pin->refresh();
        $this->assertSame('failed', $pin->status);
        $this->assertSame('Pinterest rejected the Pin: Invalid parameters: link is not a valid URL', $pin->error_message);

        $log = $pin->logs()->where('event', 'publish_error')->first();
        $this->assertSame(400, $log->http_status);
        $this->assertSame('Invalid parameters: link is not a valid URL', $log->details['pinterest_error']['message']);
        $this->assertSame(1, $pin->publishingJobs()->first()->attempts);
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
            ->assertJsonFragment(['Your daily publishing limit has been reached.']);
    }
}
