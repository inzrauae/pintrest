<?php

namespace App\Services\Ai;

use App\Models\AiProviderSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google Gemini generateContent with a JSON response schema.
 */
class GeminiClient implements LlmClient
{
    public function generateJson(string $system, string $user, array $schema, array $context, AiProviderSetting $settings): array
    {
        $key = config('services.gemini.api_key');
        if (! $key) {
            throw new AiException('The AI provider is not configured. Set GEMINI_API_KEY or switch provider in AI Settings.');
        }

        $model = $settings->model ?: config('services.gemini.model');
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => $schema,
                'temperature' => $settings->temperature,
            ],
        ];

        $response = self::post($key, $model, $payload, (int) config('services.gemini.timeout'), 'AI');

        $block = $response->json('promptFeedback.blockReason');
        $finish = $response->json('candidates.0.finishReason');
        if ($block || in_array($finish, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'RECITATION'], true)) {
            throw new AiException('The AI provider declined to generate content for this page.', details: ['reason' => $block ?? $finish]);
        }

        // Thinking models may return thought parts; only the answer parts hold the JSON.
        $text = collect($response->json('candidates.0.content.parts', []))
            ->reject(fn ($part) => ! empty($part['thought']))
            ->pluck('text')
            ->implode('');

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new AiException('The AI provider returned invalid JSON.', retryable: true, details: ['content' => mb_substr($text, 0, 1000), 'finish_reason' => $finish]);
        }

        return $decoded;
    }

    /**
     * Shared by the text and image clients: calls generateContent and maps failures to AiException.
     *
     * @throws AiException
     */
    public static function post(string $key, string $model, array $payload, int $timeout, string $label): Response
    {
        $url = rtrim(config('services.gemini.base_url'), '/').'/models/'.rawurlencode($model).':generateContent';

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $key])
                ->timeout($timeout)
                ->acceptJson()
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new AiException("Could not reach the {$label} provider.", retryable: true, previous: $e);
        }

        if ($response->failed()) {
            $status = $response->status();
            $error = $response->json('error') ?? mb_substr($response->body(), 0, 1000);
            // Gemini reports a bad key as 400 INVALID_ARGUMENT rather than 401.
            $badKey = $status === 401 || $status === 403 || str_contains((string) $response->json('error.message'), 'API key');
            // Free-tier keys get a quota of 0 for some models (e.g. image models): retrying never helps.
            $noQuota = $status === 429 && str_contains((string) $response->json('error.message'), 'limit: 0');

            throw new AiException(match (true) {
                $badKey => "The {$label} provider rejected the API key.",
                $status === 404 => "The {$label} provider does not offer the model \"{$model}\".",
                $noQuota => "Your Gemini API key is on the free tier, which has no quota for \"{$model}\". Enable billing for the key's Google Cloud project (aistudio.google.com → API keys), or use another provider.",
                $status === 429 => "The {$label} provider rate limit was reached. Please try again shortly.",
                $status >= 500 => "The {$label} provider is temporarily unavailable.",
                default => "The {$label} provider rejected the request.",
            }, retryable: ($status === 429 && ! $noQuota) || $status >= 500, details: ['status' => $status, 'error' => $error]);
        }

        return $response;
    }
}
