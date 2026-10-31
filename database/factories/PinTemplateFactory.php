<?php

namespace Database\Factories;

use App\Models\PinTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PinTemplate>
 */
class PinTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Title banner',
            'text_overlay' => true,
            'overlay_position' => 'bottom',
            'background_color' => '#000000',
            'background_opacity' => 60,
            'text_color' => '#FFFFFF',
            'font_size' => 56,
        ];
    }
}
