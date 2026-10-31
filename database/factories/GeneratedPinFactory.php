<?php

namespace Database\Factories;

use App\Models\GeneratedPin;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeneratedPin>
 */
class GeneratedPinFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => mb_substr(fake()->sentence(6), 0, 100),
            'description' => fake()->paragraph(2),
            'alt_text' => fake()->sentence(),
            'destination_url' => fake()->url(),
            'language' => 'en',
            'status' => 'draft',
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => 'published',
            'pinterest_pin_id' => (string) fake()->unique()->numberBetween(1e9, 9e9),
            'approved_at' => now()->subHour(),
            'published_at' => now(),
        ]);
    }
}
