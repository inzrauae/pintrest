<?php

namespace App\Services\Ai;

use App\Models\AiProviderSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Chat Completions with Structured Outputs. Works with any OpenAI-compatible
 * endpoint via OPENAI_BASE_URL.
 */
class OpenAiClient implements LlmClient
{
    public function generateJson(string $system, string $user, array $schema, array $context, AiProviderSetting $settings): array
    {
        $key = config('services.openai.api_key');
        if (! $key) {
            throw new AiException('The AI provider is not configured. Set OPENAI_API_KEY or switch to the offline provider in AI Settings.');
        }

        $model = $settings->model ?: config('services.openai.model');
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'pin_content', 'strict' => true, 'schema' => $schema],
            ],
        ];
        // Reasoning models only accept the default temperature.
        if (! preg_match('/^(o\d|gpt-5)/i', $model)) {
            $payload['temperature'] = $settings->temperature;
        }

        try {
            $response = Http::withToken($key)
                ->timeout((int) config('services.openai.timeout'))
                ->acceptJson()
                ->post(rtrim(config('services.openai.base_url'), '/').'/chat/completions', $payload);
        } catch (ConnectionException $e) {
            throw new AiException('Could not reach the AI provider.', retryable: true, previous: $e);
        }

        if ($response->failed()) {
            $status = $response->status();
            $details = ['status' => $status, 'error' => $response->json('error') ?? mb_substr($response->body(), 0, 1000)];

            throw new AiException(match (true) {
                $status === 401, $status === 403 => 'The AI provider rejected the API key.',
                $status === 429 => 'The AI provider rate limit was reached. Please try again shortly.',
                $status >= 500 => 'The AI provider is temporarily unavailable.',
                default => 'The AI provider rejected the request.',
            }, retryable: $status === 429 || $status >= 500, details: $details);
        }

        $message = $response->json('choices.0.message', []);
        if (! empty($message['refusal'])) {
            throw new AiException('The AI provider declined to generate content for this page.', details: ['refusal' => $message['refusal']]);
        }

        $decoded = json_decode((string) ($message['content'] ?? ''), true);
        if (! is_array($decoded)) {
            throw new AiException('The AI provider returned invalid JSON.', retryable: true, details: ['content' => mb_substr((string) ($message['content'] ?? ''), 0, 1000)]);
        }

        return $decoded;
    }
}
