<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Container\Container;

class LlmProviderFactory
{
    public const PROVIDERS = [
        'openai' => OpenAiClient::class,
        'gemini' => GeminiClient::class,
        'fake' => OfflineLlmClient::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function for(?string $provider): LlmClient
    {
        $class = self::PROVIDERS[$provider] ?? self::PROVIDERS[config('pinbot.ai.default_provider')] ?? OfflineLlmClient::class;

        return $this->container->make($class);
    }
}
