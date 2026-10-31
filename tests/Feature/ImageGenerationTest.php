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
