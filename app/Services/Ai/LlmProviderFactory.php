<?php

namespace App\Services\Ai;

use App\Models\AiProviderSetting;
use App\Support\ActivityLogger;
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

    /**
     * Calls the user's text provider; if it errors (overloaded, rate limited, bad key, no quota…) the same request
     * goes to every other provider whose API key is set, with that provider's default model.
     */
    public function generateJsonWithFallback(string $system, string $user, array $schema, array $context, AiProviderSetting $settings): array
    {
        $primary = array_key_exists($settings->provider, self::PROVIDERS) ? $settings->provider : config('pinbot.ai.default_provider');
        $order = array_values(array_unique(array_filter(
            [$primary, 'openai', 'gemini'],
            fn ($p) => $p === $primary || ($p !== 'fake' && filled(config("services.{$p}.api_key"))),
        )));

        $errors = [];
        foreach ($order as $provider) {
            $attempt = $provider === $primary ? $settings : (clone $settings)->forceFill(['provider' => $provider, 'model' => null]);
            try {
                $result = $this->for($provider)->generateJson($system, $user, $schema, $context, $attempt);
                if ($errors) {
                    ActivityLogger::log($settings->user_id, 'ai_provider_fallback', ucfirst($primary).' failed ('.reset($errors).'), used '.ucfirst($provider).' instead.', 'warning');
                }

                return $result;
            } catch (AiException $e) {
                $errors[$provider] = $e->getMessage();
                $last = $e;
            }
        }

        if (count($errors) > 1) {
            $summary = implode(' ', array_map(fn ($p, $m) => ucfirst($p).': '.$m, array_keys($errors), $errors));
            throw new AiException('All AI providers failed. '.$summary, retryable: $last->retryable, details: ['providers' => $errors] + $last->details);
        }

        throw $last;
    }
}
