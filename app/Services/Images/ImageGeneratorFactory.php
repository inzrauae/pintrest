<?php

namespace App\Services\Images;

use App\Models\ImageProviderSetting;
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

    /** Whether the provider's API key is set in the environment. */
    public function configured(string $provider): bool
    {
        return filled(config("services.{$provider}.api_key"));
    }

    /**
     * Generates with the user's provider; if that fails (bad key, no credit, outage, refusal…), retries the
     * same prompt with every other configured provider using that provider's default model.
     *
     * @return array{bytes:string, provider:string, fallback_from:?string, fallback_error:?string}
     */
    public function generateWithFallback(string $prompt, ImageProviderSetting $settings): array
    {
        $primary = $settings->provider;
        $order = array_values(array_unique(array_filter(
            [$primary, ...array_keys(self::PROVIDERS)],
            fn ($p) => $this->available($p) && ($p === $primary || $this->configured($p)),
        )));

        $errors = [];
        foreach ($order as $provider) {
            // The saved model belongs to the primary provider; others use their own default model.
            $attempt = $provider === $primary ? $settings : (clone $settings)->forceFill(['provider' => $provider, 'model' => null]);

            try {
                $bytes = $this->for($provider)->generate($prompt, $attempt);

                return [
                    'bytes' => $bytes,
                    'provider' => $provider,
                    'fallback_from' => $provider === $primary ? null : $primary,
                    'fallback_error' => $errors[$primary] ?? null,
                ];
            } catch (AiException $e) {
                $errors[$provider] = $e->getMessage();
                $last = $e;
            }
        }

        if (count($errors) > 1) {
            $summary = implode(' ', array_map(fn ($p, $m) => ucfirst($p).': '.$m, array_keys($errors), $errors));
            throw new AiException('All image providers failed. '.$summary, retryable: $last->retryable, details: ['providers' => $errors] + $last->details);
        }

        throw $last ?? new AiException('No image-generation provider is enabled. Choose one in AI Settings.');
    }
}
