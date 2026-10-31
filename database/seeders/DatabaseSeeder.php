<?php

namespace Database\Seeders;

use App\Models\PinTemplate;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Creates an admin account plus starter project and templates.
     * Credentials come from SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD (change them in production).
     */
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => env('SEED_ADMIN_EMAIL', 'admin@example.com')],
            ['name' => 'Admin', 'password' => env('SEED_ADMIN_PASSWORD', 'password')],
        );
        $admin->forceFill(['is_admin' => true])->save();
        $admin->preferences();

        Project::firstOrCreate(['user_id' => $admin->id, 'name' => 'My website'], ['language' => 'en']);

        PinTemplate::firstOrCreate(['user_id' => $admin->id, 'name' => 'Clean (no text)'], [
            'text_overlay' => false,
            'image_style' => 'bright natural light, clean editorial composition',
            'is_default' => true,
        ]);
        PinTemplate::firstOrCreate(['user_id' => $admin->id, 'name' => 'Title banner'], [
            'text_overlay' => true,
            'overlay_position' => 'bottom',
            'background_color' => '#000000',
            'background_opacity' => 60,
            'text_color' => '#FFFFFF',
            'font_size' => 56,
        ]);
    }
}
