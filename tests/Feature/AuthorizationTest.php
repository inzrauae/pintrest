<?php

namespace Tests\Feature;

use App\Models\PinterestAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_the_api(): void
    {
        $this->getJson('/api/pins')->assertUnauthorized();
        $this->postJson('/api/urls/analyze', ['url' => 'https://example.com'])->assertUnauthorized();
    }

    public function test_users_cannot_touch_each_others_data(): void
    {
        [$owner, $pin, $account] = $this->publishablePin();
        $image = $pin->images()->first();
        $intruder = User::factory()->create();
        $this->actingAs($intruder);

        $this->getJson("/api/pins/{$pin->id}")->assertForbidden();
        $this->patchJson("/api/pins/{$pin->id}", ['title' => 'Hacked title here'])->assertForbidden();
        $this->postJson("/api/pins/{$pin->id}/publish")->assertForbidden();
        $this->deleteJson("/api/pins/{$pin->id}")->assertForbidden();
        $this->get("/api/pin-images/{$image->id}/file")->assertForbidden();
        $this->deleteJson("/api/pinterest/accounts/{$account->id}")->assertForbidden();
        $this->getJson('/api/pins')->assertOk()->assertJsonCount(0, 'data');

        // Cannot attach someone else's Pinterest account to their own Pin.
        $this->actingAs($owner);
        $otherAccount = PinterestAccount::create([
            'user_id' => $intruder->id, 'pinterest_user_id' => 'x', 'access_token' => 't', 'environment' => 'production',
        ]);
        $this->patchJson("/api/pins/{$pin->id}", ['pinterest_account_id' => $otherAccount->id])->assertJsonValidationErrors('pinterest_account_id');
    }

    public function test_admin_endpoints_require_admin(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/admin/failed-jobs')->assertForbidden();

        $user->forceFill(['is_admin' => true])->save();
        $this->actingAs($user)->getJson('/api/admin/failed-jobs')->assertOk();
    }

    public function test_published_pins_are_read_only(): void
    {
        [$user, $pin] = $this->publishablePin(['status' => 'published', 'pinterest_pin_id' => '1']);

        $this->actingAs($user)->patchJson("/api/pins/{$pin->id}", ['title' => 'New title for this pin'])->assertUnprocessable();
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'a@b.test']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'a@b.test', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/auth/login', ['email' => 'a@b.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_register_login_and_me(): void
    {
        // The SPA is a first-party "stateful" frontend: Sanctum starts a session for its Origin.
        $this->withHeader('Origin', config('app.url'));

        $this->postJson('/api/auth/register', [
            'name' => 'Ana', 'email' => 'ana@example.test', 'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated();

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.email', 'ana@example.test');
        $this->assertDatabaseHas('user_preferences', ['user_id' => User::first()->id]);
    }
}
