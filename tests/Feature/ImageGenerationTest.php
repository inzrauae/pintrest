<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImageGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function fakeOpenAiImages(): void
    {
        config(['services.openai.api_key' => 'sk-test', 'services.openai.image_model' => 'gpt-image-1.5']);
        Http::fake(['https://api.openai.com/*' => Http::response(['data' => [['b64_json' => base64_encode($this->jpeg(1024, 1536))]]])]);
    }

    public function test_generated_image_illustrates_the_pin_description(): void
    {
        [$user, $pin] = $this->publishablePin(['image_generation_prompt' => 'Stale prompt about something else']);
        $user->imageSettingsOrDefault()->update(['provider' => 'openai', 'model' => null]);
        $this->fakeOpenAiImages();

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/images/generate", [])->assertCreated();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'images/generations')
            && $r['size'] === '1024x1536'
            && str_contains($r['prompt'], '2:3 aspect ratio (1000x1500)')
            && str_contains($r['prompt'], 'Title: "How to Grow Basil Indoors"')
            && str_contains($r['prompt'], 'A practical windowsill guide to growing basil indoors')
            && ! str_contains($r['prompt'], 'Stale prompt'));
        $this->assertSame('Stale prompt about something else', $pin->fresh()->image_generation_prompt);
    }

    public function test_invalid_api_key_is_explained(): void
    {
        [$user, $pin] = $this->publishablePin();
        $user->imageSettingsOrDefault()->update(['provider' => 'openai', 'model' => null]);
        config(['services.openai.api_key' => 'OPENAI_API_KEY=sk-wrong', 'services.gemini.api_key' => null]);
        Http::fake(['https://api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key provided', 'code' => 'invalid_api_key']], 401)]);

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/images/generate", [])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'OpenAI rejected the API key. Check OPENAI_API_KEY in .env (it must be only the key, starting with "sk-"), then restart the app and queue worker.']);
    }

    public function test_falls_back_to_gemini_when_openai_fails(): void
    {
        [$user, $pin] = $this->publishablePin();
        $user->imageSettingsOrDefault()->update(['provider' => 'openai', 'model' => 'gpt-image-1.5']);
        config(['services.openai.api_key' => 'sk-wrong', 'services.gemini.api_key' => 'gemini-test', 'services.gemini.image_model' => 'gemini-3.1-flash-image']);
        Http::fake([
            'https://api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key provided', 'code' => 'invalid_api_key']], 401),
            'https://generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode($this->jpeg(1024, 1536))]]]]]]]),
        ]);

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/images/generate", [])->assertCreated();

        // Gemini gets the same description-based prompt, with its own default model (not the OpenAI one).
        Http::assertSent(fn ($r) => str_contains($r->url(), 'models/gemini-3.1-flash-image:generateContent')
            && str_contains(json_encode($r->data()), 'A practical windowsill guide to growing basil indoors'));
        $this->assertSame(1, $pin->images()->where('source', 'generated')->count());
        $this->assertSame('gpt-image-1.5', $user->imageSettingsOrDefault()->fresh()->model, 'saved settings are untouched');
        $this->assertDatabaseHas('publication_logs', ['event' => 'image_provider_fallback', 'user_id' => $user->id]);
    }

    public function test_reports_both_errors_when_every_provider_fails(): void
    {
        [$user, $pin] = $this->publishablePin();
        $user->imageSettingsOrDefault()->update(['provider' => 'openai', 'model' => null]);
        config(['services.openai.api_key' => 'sk-wrong', 'services.gemini.api_key' => 'gemini-test']);
        Http::fake([
            'https://api.openai.com/*' => Http::response(['error' => ['code' => 'insufficient_quota']], 429),
            'https://generativelanguage.googleapis.com/*' => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY']]),
        ]);

        $message = $this->actingAs($user)->postJson("/api/pins/{$pin->id}/images/generate", [])->assertStatus(422)->json('message');

        $this->assertStringContainsString('All image providers failed.', $message);
        $this->assertStringContainsString('Openai: Your OpenAI account has no remaining credit', $message);
        $this->assertStringContainsString('Gemini: The image provider rejected the prompt.', $message);
    }

    public function test_custom_prompt_overrides_the_description(): void
    {
        [$user, $pin] = $this->publishablePin();
        $user->imageSettingsOrDefault()->update(['provider' => 'openai', 'model' => null]);
        $this->fakeOpenAiImages();

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/images/generate", ['prompt' => 'Basil seedlings in terracotta pots'])->assertCreated();

        Http::assertSent(fn ($r) => str_contains($r['prompt'], 'Basil seedlings in terracotta pots')
            && ! str_contains($r['prompt'], 'A practical windowsill guide'));
        $this->assertSame('Basil seedlings in terracotta pots', $pin->fresh()->image_generation_prompt);
    }
}
