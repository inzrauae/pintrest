<?php

namespace App\Services\Images;

use App\Services\Ai\AiException;
use Illuminate\Contracts\Container\Container;

class ImageGeneratorFactory
{
    public const PROVIDERS = [
        'openai' => OpenAiImageGenerator::class,
        'gemini' => GeminiImageGenerator::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function available(?string $provider): bool
    {
        return isset(self::PROVIDERS[$provider]);
    }

    public function for(?string $provider): ImageGenerator
    {
        if (! $this->available($provider)) {
            throw new AiException('No image-generation provider is enabled. Choose one in AI Settings.');
        }

        return $this->container->make(self::PROVIDERS[$provider]);
    }
}
