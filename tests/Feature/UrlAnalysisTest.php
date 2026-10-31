<?php

namespace Tests\Feature;

use App\Models\GeneratedPin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UrlAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSite(): void
    {
        Http::fake([
            'https://example.com/basil-guide*' => fn () => Http::response($this->articleHtml(), 200, ['Content-Type' => 'text/html; charset=utf-8']),
            'https://example.com/img/*' => fn () => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    public function test_single_url_generates_a_reviewable_pin(): void
    {
        $this->fakeSite();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'https://example.com/basil-guide']);

        $response->assertCreated()
            ->assertJsonPath('pin.status', 'draft')
            ->assertJsonPath('pin.title', 'How to Grow Basil Indoors')
            ->assertJsonPath('pin.destination_url', 'https://example.com/basil-guide');

        $pin = GeneratedPin::with('images', 'keywords')->first();
        $this->assertNotEmpty($pin->keywordList());
        $this->assertGreaterThanOrEqual(1, $pin->images->count());

        $selected = $pin->images->firstWhere('is_selected', true);
        $this->assertSame('extracted', $selected->source);
        $this->assertFalse($selected->rights_confirmed, 'third-party images need explicit confirmation');
        $this->assertSame(1000, $selected->width);
        $this->assertSame(1500, $selected->height, 'rendered at 2:3');

        $this->get($selected->url)->assertOk();
    }

    public function test_duplicate_submission_returns_409_unless_forced(): void
    {
        $this->fakeSite();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'https://example.com/basil-guide'])->assertCreated();

        $this->actingAs($user)
            ->postJson('/api/urls/analyze', ['url' => 'https://EXAMPLE.com/basil-guide/?utm_source=pinterest'])
            ->assertStatus(409)
            ->assertJsonPath('duplicate', true);

        $this->actingAs($user)
            ->postJson('/api/urls/analyze', ['url' => 'https://example.com/basil-guide', 'force' => true])
            ->assertCreated();

        $titles = GeneratedPin::pluck('title');
        $this->assertCount(2, $titles);
        $this->assertNotSame($titles[0], $titles[1], 'second Pin must not reuse the same title');
    }

    public function test_invalid_and_private_urls_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'not a url'])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'http://192.168.0.1/admin'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.url.0', 'This address points to a private or restricted network and cannot be fetched.');
    }

    public function test_page_without_images_produces_a_warning(): void
    {
        Http::fake(['https://example.com/text' => Http::response('<html><head><title>Plain Text Article About Herbs</title><meta name="description" content="Plain text article describing many herbs you can grow at home easily."></head><body><p>Herbs.</p></body></html>', 200, ['Content-Type' => 'text/html'])]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'https://example.com/text'])
            ->assertCreated()
            ->assertJsonPath('pin.warnings.0', 'No usable image was found on the page. Generate an AI image or upload your own.');
    }

    public function test_inaccessible_page_reports_a_clear_error(): void
    {
        Http::fake(['https://example.com/missing' => Http::response('nope', 404)]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'https://example.com/missing'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The website responded with HTTP 404.');
    }

    public function test_processing_limit_is_enforced(): void
    {
        $this->fakeSite();
        $user = User::factory()->create();
        $user->preferences()->update(['hourly_processing_limit' => 1]);

        $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'https://example.com/basil-guide'])->assertCreated();
        $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'https://example.com/basil-guide?page=2'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');
    }

    public function test_regenerating_a_single_field_keeps_the_others(): void
    {
        $this->fakeSite();
        $user = User::factory()->create();
        $pinId = $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'https://example.com/basil-guide'])->json('pin.id');
        $before = GeneratedPin::find($pinId);

        $this->actingAs($user)->postJson("/api/pins/{$pinId}/regenerate", ['fields' => ['title']])->assertOk();

        $after = $before->fresh();
        $this->assertNotSame($before->title, $after->title);
        $this->assertSame($before->description, $after->description);
    }

    public function test_invalid_ai_output_is_retried_then_fails_cleanly(): void
    {
        $this->fakeSite();
        config(['services.openai.api_key' => 'sk-test']);
        Http::fake([
            'https://api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{"pin_title":"x"}']]]]),
        ]);
        $user = User::factory()->create();
        $user->aiSettingsOrDefault()->update(['provider' => 'openai']);

        $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'https://example.com/basil-guide'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The AI response could not be validated after 3 attempts.');

        $this->assertSame(3, Http::recorded(fn ($r) => str_contains($r->url(), 'openai.com'))->count());
    }

    public function test_openai_structured_output_is_used_when_configured(): void
    {
        $this->fakeSite();
        config(['services.openai.api_key' => 'sk-test']);
        $content = json_encode([
            'pin_title' => 'Grow Basil Indoors: A Windowsill Guide',
            'pin_description' => 'Everything you need to grow basil indoors: six hours of light, smart watering and pruning for a steady harvest.',
            'keywords' => ['indoor basil', 'windowsill herbs', 'grow basil'],
            'suggested_hashtags' => ['#basil'],
            'image_selection_reason' => 'Open Graph image shows the plant.',
            'selected_image_index' => 0,
            'image_generation_prompt' => 'Basil on a sunny windowsill',
            'suggested_board' => 'Gardening',
            'destination_url' => 'https://example.com/basil-guide',
            'alt_text' => 'Potted basil on a windowsill',
        ]);
        Http::fake(['https://api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => $content]]]])]);
        $user = User::factory()->create();
        $user->aiSettingsOrDefault()->update(['provider' => 'openai']);

        $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'https://example.com/basil-guide', 'language' => 'es'])
            ->assertCreated()
            ->assertJsonPath('pin.title', 'Grow Basil Indoors: A Windowsill Guide');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'chat/completions')
            && $r['response_format']['type'] === 'json_schema'
            && str_contains($r['messages'][0]['content'], 'Spanish'));
    }

    public function test_gemini_json_schema_output_is_used_when_selected(): void
    {
        $this->fakeSite();
        config(['services.gemini.api_key' => 'gemini-test', 'services.gemini.model' => 'gemini-3.5-flash']);
        $content = json_encode([
            'pin_title' => 'Grow Basil Indoors: A Windowsill Guide',
            'pin_description' => 'Everything you need to grow basil indoors: six hours of light, smart watering and pruning for a steady harvest.',
            'keywords' => ['indoor basil', 'windowsill herbs', 'grow basil'],
            'suggested_hashtags' => ['#basil'],
            'image_selection_reason' => 'Open Graph image shows the plant.',
            'selected_image_index' => 0,
            'image_generation_prompt' => 'Basil on a sunny windowsill',
            'suggested_board' => 'Gardening',
            'destination_url' => 'https://example.com/basil-guide',
            'alt_text' => 'Potted basil on a windowsill',
        ]);
        Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'thinking…', 'thought' => true], ['text' => $content]]], 'finishReason' => 'STOP']],
        ])]);
        $user = User::factory()->create();
        $user->aiSettingsOrDefault()->update(['provider' => 'gemini', 'model' => null]);

        $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'https://example.com/basil-guide', 'language' => 'es'])
            ->assertCreated()
            ->assertJsonPath('pin.title', 'Grow Basil Indoors: A Windowsill Guide');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'models/gemini-3.5-flash:generateContent')
            && $r->header('x-goog-api-key') === ['gemini-test']
            && $r['generationConfig']['responseMimeType'] === 'application/json'
            && str_contains($r['systemInstruction']['parts'][0]['text'], 'Spanish'));
    }

    public function test_overloaded_gemini_falls_back_to_openai(): void
    {
        $this->fakeSite();
        config(['services.gemini.api_key' => 'gemini-test', 'services.openai.api_key' => 'sk-test', 'services.openai.model' => 'gpt-4o-mini']);
        $content = json_encode([
            'pin_title' => 'Grow Basil Indoors: A Windowsill Guide',
            'pin_description' => 'Everything you need to grow basil indoors: six hours of light, smart watering and pruning for a steady harvest.',
            'keywords' => ['indoor basil', 'windowsill herbs', 'grow basil'], 'suggested_hashtags' => ['#basil'], 'image_selection_reason' => 'Open Graph image shows the plant.',
            'selected_image_index' => 0, 'image_generation_prompt' => 'Basil', 'suggested_board' => 'Gardening',
            'destination_url' => 'https://example.com/basil-guide', 'alt_text' => 'Basil',
        ]);
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 503, 'message' => 'This model is currently experiencing high demand.', 'status' => 'UNAVAILABLE']], 503),
            'https://api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']]]),
        ]);
        $user = User::factory()->create();
        $user->aiSettingsOrDefault()->update(['provider' => 'gemini', 'model' => 'gemini-3.5-flash']);

        $r = $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'https://example.com/basil-guide']);
        $r->assertCreated();
        $r->assertJsonPath('pin.title', 'Grow Basil Indoors: A Windowsill Guide');

        // OpenAI gets its own default model, not the Gemini one saved in the user's settings.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'chat/completions') && $r['model'] === 'gpt-4o-mini');
        $this->assertDatabaseHas('publication_logs', ['event' => 'ai_provider_fallback', 'user_id' => $user->id]);
    }

    public function test_reports_every_provider_error_when_all_fail(): void
    {
        $this->fakeSite();
        config(['services.gemini.api_key' => 'gemini-test', 'services.openai.api_key' => 'sk-test']);
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 503, 'message' => 'high demand']], 503),
            'https://api.openai.com/*' => Http::response(['error' => ['message' => 'down']], 502),
        ]);
        $user = User::factory()->create();
        $user->aiSettingsOrDefault()->update(['provider' => 'gemini', 'model' => null]);

        $message = $this->actingAs($user)->postJson('/api/urls/analyze', ['url' => 'https://example.com/basil-guide'])->assertStatus(422)->json('message');

        $this->assertStringContainsString('All AI providers failed.', $message);
        $this->assertStringContainsString('Gemini:', $message);
        $this->assertStringContainsString('Openai:', $message);
    }

    public function test_gemini_bad_key_is_reported_as_a_rejected_key(): void
    {
        $this->fakeSite();
        config(['services.gemini.api_key' => 'bad-key']);
        Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['code' => 400, 'message' => 'API key not valid. Please pass a valid API key.', 'status' => 'INVALID_ARGUMENT'],
        ], 400)]);

        $this->expectException(\App\Services\Ai\AiException::class);
        $this->expectExceptionMessage('The AI provider rejected the API key.');

        (new \App\Services\Ai\GeminiClient)->generateJson('system', 'user', ['type' => 'object'], [], new \App\Models\AiProviderSetting(['temperature' => 0.7]));
    }
}
