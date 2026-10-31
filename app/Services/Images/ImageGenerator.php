<?php

namespace App\Services\Images;

use App\Models\ImageProviderSetting;

interface ImageGenerator
{
    /** Generates a vertical image and returns its raw bytes. */
    public function generate(string $prompt, ImageProviderSetting $settings): string;
}
