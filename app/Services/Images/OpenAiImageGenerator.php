<?php

namespace App\Services\Images;

use App\Models\ImageProviderSetting;
use App\Services\Ai\AiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OpenAiImageGenerator implements ImageGenerator
{
    public function generate(string $prompt, ImageProviderSetting $settings): string
    {
        $key = config('services.openai.api_key');
        if (! $key) {
            throw new AiException('Image generation is not configured. Set OPENAI_API_KEY.');
        }

        $model = $settings->model ?: config('services.openai.image_model');
        $payload = ['model' => $model, 'prompt' => $prompt, 'n' => 1];

        if (str_starts_with($model, 'dall-e')) {
            $payload += ['size' => '1024x1792', 'response_format' => 'b64_json'];
        } else {
            // gpt-image models: 1024x1536 is exactly 2:3.
            $payload += ['size' => '1024x1536', 'quality' => $settings->quality ?: 'medium'];
        }

        try {
            $response = Http::withToken($key)
                ->timeout(180)
                ->acceptJson()
                ->post(rtrim(config('services.openai.base_url'), '/').'/images/generations', $payload);
        } catch (ConnectionException $e) {
            throw new AiException('Could not reach the image provider.', retryable: true, previous: $e);
        }

        if ($response->failed()) {
            $status = $response->status();
            throw new AiException(
                $status === 400 ? 'The image provider rejected the prompt.' : 'Image generation failed.',
                retryable: $status === 429 || $status >= 500,
                details: ['status' => $status, 'error' => $response->json('error')],
            );
        }

        $b64 = $response->json('data.0.b64_json');
        if (! $b64 || ($bytes = base64_decode($b64, true)) === false) {
            throw new AiException('The image provider returned no image.', retryable: true);
        }

        return $bytes;
    }
}
