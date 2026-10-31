<?php

namespace Tests\Feature;

use App\Models\GeneratedPin;
use App\Models\PinterestAccount;
use App\Models\PinterestBoard;
use App\Models\User;
use App\Services\Pinterest\PinPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProfileSeoTest extends TestCase
{
    use RefreshDatabase;

    private function setUpUser(): array
    {
        $user = User::factory()->create();
        $account = PinterestAccount::create([
            'user_id' => $user->id, 'pinterest_user_id' => 'pu_p', 'username' => 'possystemlk', 'environment' => 'production',
            'access_token' => 'access-1', 'refresh_token' => 'r', 'token_expires_at' => now()->addDays(10),
            'scopes' => 'boards:read,pins:read,pins:write,user_accounts:read,ads:read', 'status' => 'active',
        ]);
        PinterestBoard::create(['pinterest_account_id' => $account->id, 'board_id' => 'b_pos', 'name' => 'POS System Ideas']);
        PinterestBoard::create(['pinterest_account_id' => $account->id, 'board_id' => 'b_misc', 'name' => 'Inspiration']);
        Http::fake([
            'https://api.pinterest.com/v5/user_account' => Http::response([
                'username' => 'possystemlk', 'business_name' => 'POS System LK', 'about' => 'Retail and restaurant software in Sri Lanka.',
                'website_url' => 'https://possystem.lk', 'account_type' => 'BUSINESS', 'follower_count' => 120, 'monthly_views' => 5400,
                'pin_count' => 300, 'board_count' => 2,
            ]),
            'https://api.pinterest.com/v5/terms/*' => Http::response([]),
            'https://api.pinterest.com/v5/trends/*' => Http::response(['trends' => []]),
        ]);

        return [$user, $account];
    }

    /** Requests a profile Pin (queued; the test queue runs it at once) and returns it. */
    private function profilePin(User $user, string $keyword): GeneratedPin
    {
        $id = $this->actingAs($user)->postJson('/api/seo/profile/pins', ['keyword' => $keyword])->assertStatus(202)->json('submission_id');

        return GeneratedPin::where('url_submission_id', $id)->firstOrFail();
    }

    public function test_profile_is_read_and_audited(): void
    {
        [$user] = $this->setUpUser();

        $data = $this->actingAs($user)->getJson('/api/seo/profile')->assertOk()->json('data');

        $this->assertSame('https://www.pinterest.com/possystemlk/', $data['profile']['profile_url']);
        $this->assertSame(5400, $data['profile']['monthly_views']);
        $checks = collect($data['audit']['checks'])->keyBy('key');
        $this->assertTrue($checks['business']['passed']);
        $this->assertTrue($checks['website']['passed']);
        $this->assertFalse($checks['profile_pins']['passed']);
    }

    public function test_profile_pin_links_to_the_profile_and_uses_the_matching_board(): void
    {
        [$user, $account] = $this->setUpUser();

        $pinId = $this->profilePin($user, 'pos system for pharmacy')->id;

        $pin = GeneratedPin::find($pinId);
        $this->assertSame('https://www.pinterest.com/possystemlk/', $pin->destination_url);
        $this->assertSame([$account->id, 'b_pos'], [$pin->pinterest_account_id, $pin->board_id]);
        $this->assertSame(['profile', 'pos system for pharmacy'], [$pin->ai_metadata['kind'], $pin->ai_metadata['profile_keyword']]);
        $this->assertSame('draft', $pin->status);
        $this->assertStringContainsStringIgnoringCase('pos system for pharmacy', $pin->title);

        // It shows up on the profile card.
        $this->assertSame($pinId, $this->actingAs($user)->getJson('/api/seo/profile')->json('data.pins.0.id'));
    }

    public function test_on_trial_access_profile_pins_publish_from_the_sandbox_account(): void
    {
        config(['pinbot.pinterest_limits.access_level' => 'trial']);
        [$user] = $this->setUpUser();
        $sandbox = PinterestAccount::create([
            'user_id' => $user->id, 'pinterest_user_id' => 'pu_s', 'username' => 'possystemlk', 'environment' => 'sandbox',
            'access_token' => 's', 'refresh_token' => 'r', 'token_expires_at' => now()->addDays(10), 'status' => 'active',
        ]);
        PinterestBoard::create(['pinterest_account_id' => $sandbox->id, 'board_id' => 'sb_pos', 'name' => 'POS System Ideas']);

        $pin = $this->profilePin($user, 'pos system ideas');

        $this->assertSame([$sandbox->id, 'sb_pos'], [$pin->pinterest_account_id, $pin->board_id]);
        $this->assertSame('https://www.pinterest.com/possystemlk/', $pin->destination_url, 'still links to the real profile');
    }

    public function test_profile_pins_are_not_treated_as_duplicates_of_each_other(): void
    {
        [$user] = $this->setUpUser();
        $first = $this->profilePin($user, 'pos system ideas');
        $first->update(['status' => 'published', 'pinterest_pin_id' => '1']);
        $second = $this->profilePin($user, 'retail pos system');

        $this->assertNull(app(PinPublisher::class)->duplicateOf($second));
    }

    public function test_suggested_profile_text_uses_keywords(): void
    {
        [$user] = $this->setUpUser();
        \App\Models\SeoTopic::create(['user_id' => $user->id, 'name' => 'POS', 'keywords' => ['pos system sri lanka', 'billing software']]);

        $data = $this->actingAs($user)->postJson('/api/seo/profile/suggest')->assertOk()->json('data');

        $this->assertLessThanOrEqual(65, mb_strlen($data['display_name']));
        $this->assertLessThanOrEqual(500, mb_strlen($data['about']));
        $this->assertStringContainsStringIgnoringCase('pos system sri lanka', $data['display_name'].' '.$data['about']);
    }

    public function test_creating_a_profile_pin_only_queues_the_slow_work(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        [$user] = $this->setUpUser();

        $id = $this->actingAs($user)->postJson('/api/seo/profile/pins', ['keyword' => 'pos system ideas'])->assertStatus(202)->json('submission_id');

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\CreateProfilePin::class, fn ($job) => $job->submissionId === $id && $job->keyword === 'pos system ideas');
        $this->assertSame('pending', \App\Models\UrlSubmission::find($id)->status, 'shown as an in-progress card in the Pin Library');
        $this->assertSame(0, GeneratedPin::count());
    }

    public function test_profile_endpoints_need_a_connected_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/seo/profile')->assertOk()->assertJsonPath('data', null);
        $this->actingAs($user)->postJson('/api/seo/profile/pins', ['keyword' => 'pos system'])->assertUnprocessable();
    }
}
