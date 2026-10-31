<?php

namespace Database\Factories;

use App\Models\PinterestAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PinterestAccount>
 */
class PinterestAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'pinterest_user_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'username' => fake()->userName(),
            'account_type' => 'BUSINESS',
            'environment' => 'sandbox',
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_expires_at' => now()->addDays(30),
            'scopes' => 'boards:read,boards:write,pins:read,pins:write,user_accounts:read',
            'status' => 'active',
            'connected_at' => now(),
        ];
    }
}
