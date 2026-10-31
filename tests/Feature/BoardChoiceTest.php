<?php

namespace Tests\Feature;

use App\Models\GeneratedPin;
use App\Models\PinterestAccount;
use App\Models\PinterestBoard;
use App\Models\UrlBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BoardChoiceTest extends TestCase
{
    use RefreshDatabase;

    private function setUpUser(): array
    {
        $user = User::factory()->create();
        $account = PinterestAccount::create([
            'user_id' => $user->id, 'pinterest_user_id' => 'pu_b', 'username' => 'b', 'environment' => 'sandbox',
            'access_token' => 'a', 'refresh_token' => 'r', 'token_expires_at' => now()->addDays(10), 'status' => 'active',
        ]);
        PinterestBoard::create(['pinterest_account_id' => $account->id, 'board_id' => 'b_default', 'name' => 'Default']);
        PinterestBoard::create(['pinterest_account_id' => $account->id, 'board_id' => 'b_chosen', 'name' => 'Herb Garden Ideas']);
        $user->preferences()->update(['default_pinterest_account_id' => $account->id, 'default_board_id' => 'b_default']);
        Http::fake([
            'https://example.com/img/*' => fn () => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
            'https://example.com/*' => fn () => Http::response($this->articleHtml(), 200, ['Content-Type' => 'text/html']),
        ]);

        return [$user, $account];
    }

    public function test_single_pin_goes_to_the_chosen_board(): void
    {
        [$user, $account] = $this->setUpUser();

        $pinId = $this->actingAs($user)->postJson('/api/urls/analyze', [
            'url' => 'https://example.com/basil-guide', 'pinterest_account_id' => $account->id, 'board_id' => 'b_chosen',
        ])->assertCreated()->json('pin.id');

        $this->assertSame('b_chosen', GeneratedPin::find($pinId)->board_id);
    }

    public function test_without_a_choice_the_default_board_is_used(): void
    {
        [$user] = $this->setUpUser();

        $pinId = $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'https://example.com/basil-guide'])->assertCreated()->json('pin.id');

        $this->assertSame('b_default', GeneratedPin::find($pinId)->board_id);
    }

    public function test_bulk_and_website_batches_put_every_pin_on_the_chosen_board(): void
    {
        [$user, $account] = $this->setUpUser();

        $bulk = $this->actingAs($user)->postJson('/api/urls/bulk', [
            'urls' => "https://example.com/a\nhttps://example.com/b", 'pinterest_account_id' => $account->id, 'board_id' => 'b_chosen',
        ])->assertCreated()->json('data.id');
        $site = $this->actingAs($user)->postJson('/api/sites/import', [
            'url' => 'https://example.com', 'urls' => ['https://example.com/c'], 'pinterest_account_id' => $account->id, 'board_id' => 'b_chosen',
        ])->assertCreated()->json('data.id');

        foreach ([$bulk, $site] as $batchId) {
            $boards = GeneratedPin::whereIn('url_submission_id', UrlBatch::find($batchId)->submissions()->pluck('id'))->pluck('board_id')->unique()->values()->all();
            $this->assertSame(['b_chosen'], $boards);
        }
    }

    public function test_a_board_from_another_account_is_rejected(): void
    {
        [$user, $account] = $this->setUpUser();
        $other = PinterestAccount::create([
            'user_id' => User::factory()->create()->id, 'pinterest_user_id' => 'x', 'username' => 'x', 'environment' => 'sandbox', 'access_token' => 'x', 'status' => 'active',
        ]);
        PinterestBoard::create(['pinterest_account_id' => $other->id, 'board_id' => 'theirs', 'name' => 'Theirs']);

        $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'https://example.com/x', 'pinterest_account_id' => $account->id, 'board_id' => 'theirs'])
            ->assertUnprocessable()->assertJsonValidationErrors('board_id');
        $this->actingAs($user)->postJson('/api/urls/bulk', ['urls' => 'https://example.com/x', 'pinterest_account_id' => $other->id, 'board_id' => 'theirs'])
            ->assertUnprocessable()->assertJsonValidationErrors('pinterest_account_id');
    }
}
