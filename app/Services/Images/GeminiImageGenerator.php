<?php

namespace App\Services\Images;

use App\Models\ImageProviderSetting;
use App\Services\Ai\AiException;
use App\Services\Ai\GeminiClient;

class GeminiImageGenerator implements ImageGenerator
{
    public function generate(string $prompt, ImageProviderSetting $settings): string
    {
        $key = config('services.gemini.api_key');
        if (! $key) {
            throw new AiException('Image generation is not configured. Set GEMINI_API_KEY.');
        }

        $model = $settings->model ?: config('services.gemini.image_model');
        $payload = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
                'imageConfig' => ['aspectRatio' => '2:3'],
            ],
        ];

        $response = GeminiClient::post($key, $model, $payload, 180, 'image');

        $b64 = collect($response->json('candidates.0.content.parts', []))
            ->pluck('inlineData.data')
            ->filter()
            ->first();

        if (! $b64 || ($bytes = base64_decode($b64, true)) === false) {
            $reason = $response->json('promptFeedback.blockReason') ?? $response->json('candidates.0.finishReason');
            throw new AiException(
                in_array($reason, ['SAFETY', 'PROHIBITED_CONTENT', 'IMAGE_SAFETY'], true) ? 'The image provider rejected the prompt.' : 'The image provider returned no image.',
                retryable: ! $reason || $reason === 'STOP',
                details: ['reason' => $reason],
            );
        }

        return $bytes;
    }
}
