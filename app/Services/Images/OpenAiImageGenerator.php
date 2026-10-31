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
            $code = (string) $response->json('error.code');
            $message = match (true) {
                $status === 401 || $code === 'invalid_api_key' => 'OpenAI rejected the API key. Check OPENAI_API_KEY in .env (it must be only the key, starting with "sk-"), then restart the app and queue worker.',
                $code === 'insufficient_quota' => 'Your OpenAI account has no remaining credit or quota. Add billing at platform.openai.com, or switch the image provider in AI Settings.',
                $status === 403 => "Your OpenAI organization is not allowed to use the image model \"{$model}\" (it may need verification). Choose another model in AI Settings.",
                $status === 404 || $code === 'model_not_found' => "The image model \"{$model}\" does not exist for this API key. Choose another model in AI Settings.",
                $status === 400 && $code === 'moderation_blocked' => 'OpenAI blocked this prompt by its safety system. Edit the description or use a custom prompt.',
                $status === 400 => 'OpenAI rejected the request: '.mb_substr((string) $response->json('error.message'), 0, 200),
                $status === 429 => 'OpenAI rate limit reached. The image will be retried later.',
                default => "Image generation failed (OpenAI HTTP {$status}).",
            };
            throw new AiException(
                $message,
                retryable: ($status === 429 && $code !== 'insufficient_quota') || $status >= 500,
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
