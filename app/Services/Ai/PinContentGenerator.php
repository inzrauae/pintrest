<?php

namespace App\Services\Ai;

use App\Models\ExtractedPageData;
use App\Models\GeneratedPin;
use App\Models\PinterestBoard;
use App\Models\UrlSubmission;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Builds the prompt, calls the configured LLM, validates the structured output and
 * retries with the validation errors when the response is unusable.
 */
class PinContentGenerator
{
    public function __construct(
        private readonly LlmProviderFactory $providers,
        private readonly PinContentValidator $validator,
    ) {}

    /**
     * @param  list<string>|null  $fields  Restrict to these output fields (regeneration). Null = all.
     */
    public function generate(UrlSubmission $submission, ExtractedPageData $page, ?array $fields = null, ?GeneratedPin $current = null): array
    {
        $user = $submission->user;
        $settings = $user->aiSettingsOrDefault();
        $client = $this->providers->for($settings->provider);
        $fields ??= PinContentValidator::FIELDS;

        $allowedHosts = array_values(array_unique(array_filter([
            parse_url($submission->url, PHP_URL_HOST),
            parse_url((string) $page->final_url, PHP_URL_HOST),
            parse_url((string) $page->canonical_url, PHP_URL_HOST),
        ])));
        $images = array_values($page->images ?? []);
        $avoidTitles = $this->recentTitles($user, $current?->id);
        $boards = $this->boardNames($user);
        $languageCode = $submission->language ?: 'en';
        $language = config("pinbot.languages.{$languageCode}", $languageCode);

        $system = $this->systemPrompt($language, $settings->tone, $settings->extra_instructions);
        $basePrompt = $this->userPrompt($page, $submission, $images, $boards, $avoidTitles, $fields, $current);

        $context = [
            'page' => $page->only(['title', 'meta_description', 'og_title', 'og_description', 'canonical_url', 'final_url', 'headings', 'content']),
            'images' => $images,
            'boards' => $boards,
            'destination_url' => $page->canonical_url ?: $page->final_url ?: $submission->url,
            'variant' => 0,
        ];

        $maxAttempts = (int) config('pinbot.ai.max_attempts');
        $errors = [];
        $lastRaw = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $prompt = $basePrompt;
            if ($errors) {
                $prompt .= "\n\nYour previous answer was rejected for these reasons. Fix them:\n- ".implode("\n- ", $errors);
            }
            $context['variant'] = $attempt - 1 + ($current ? 1 : 0);

            $lastRaw = $client->generateJson($system, $prompt, $this->schema(), $context, $settings);
            [$clean, $errors] = $this->validator->validate($lastRaw, $fields, $allowedHosts, count($images), $avoidTitles);

            if ($errors === []) {
                $clean['_meta'] = ['provider' => $settings->provider, 'model' => $settings->model, 'attempts' => $attempt];

                return $clean;
            }
        }

        throw new AiException(
            'The AI response could not be validated after '.$maxAttempts.' attempts.',
            retryable: true,
            details: ['errors' => $errors, 'last_response' => $lastRaw],
        );
    }

    public function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => PinContentValidator::FIELDS,
            'properties' => [
                'pin_title' => $string + ['description' => 'Pinterest Pin title, 40-100 characters, no hashtags.'],
                'pin_description' => $string + ['description' => '150-450 characters, natural sentences, accurate to the page.'],
                'keywords' => ['type' => 'array', 'items' => $string, 'description' => '5-12 search phrases people would type on Pinterest.'],
                'suggested_hashtags' => ['type' => 'array', 'items' => $string, 'description' => '0-5 relevant hashtags.'],
                'image_selection_reason' => $string,
                'selected_image_index' => ['type' => 'integer', 'description' => 'Index of best image from the provided list, or -1 if none is suitable.'],
                'image_generation_prompt' => $string + ['description' => 'Prompt for a vertical 2:3 image that accurately represents the page. No text in the image.'],
                'suggested_board' => $string,
                'destination_url' => $string + ['description' => 'The canonical URL of the submitted page.'],
                'alt_text' => $string + ['description' => 'Accessible description of the selected image, max 500 characters.'],
            ],
        ];
    }

    private function systemPrompt(string $language, string $tone, ?string $extra): string
    {
        $prompt = <<<TXT
You are an expert Pinterest SEO copywriter. You write Pin content that is accurate, helpful and click-worthy without being clickbait.

Rules:
- Use ONLY facts present in the provided page data. Never invent prices, statistics, features, awards, discounts or claims.
- Titles: natural, compelling, specific to the page, 40-100 characters, no hashtags, no ALL CAPS.
- Descriptions: 150-450 characters, accurately summarise what the visitor will find, include the most relevant search phrases naturally, end with a soft call to action.
- Keywords: phrases matching the actual content and realistic Pinterest search intent. No keyword stuffing, no unrelated trending terms.
- Hashtags are optional and must be relevant.
- destination_url must be the submitted page's canonical URL.
- Write all text in {$language}. Tone: {$tone}.
- Respond with a single JSON object matching the schema.
TXT;

        return $extra ? $prompt."\n\nAdditional instructions from the user:\n".Str::limit(strip_tags($extra), 1000) : $prompt;
    }

    private function userPrompt(ExtractedPageData $page, UrlSubmission $submission, array $images, array $boards, array $avoidTitles, array $fields, ?GeneratedPin $current): string
    {
        $data = array_filter([
            'submitted_url' => $submission->url,
            'final_url' => $page->final_url,
            'canonical_url' => $page->canonical_url,
            'title' => $page->title,
            'meta_description' => $page->meta_description,
            'og_title' => $page->og_title,
            'og_description' => $page->og_description,
            'headings' => collect($page->headings ?? [])->take(20)->map(fn ($h) => "{$h['level']}: {$h['text']}")->all(),
            'content' => Str::limit((string) $page->content, (int) config('pinbot.ai.content_chars_for_prompt')),
        ]);

        $imageList = collect($images)->map(fn ($img, $i) => [
            'index' => $i,
            'source' => $img['source'] ?? 'img',
            'alt' => $img['alt'] ?? null,
            'width' => $img['width'] ?? null,
            'height' => $img['height'] ?? null,
            'url' => Str::limit($img['url'], 300, ''),
        ])->all();

        $prompt = "PAGE DATA:\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $prompt .= "\n\nCANDIDATE IMAGES (prefer vertical, large, content-relevant images; avoid logos/icons):\n".json_encode($imageList, JSON_UNESCAPED_SLASHES);

        if ($boards) {
            $prompt .= "\n\nTHE USER'S BOARDS (suggest one of these if it fits, else propose a short new board name):\n- ".implode("\n- ", $boards);
        }
        if ($avoidTitles) {
            $prompt .= "\n\nTITLES ALREADY USED (do not repeat or closely paraphrase):\n- ".implode("\n- ", array_slice($avoidTitles, 0, 25));
        }
        if ($current && $fields !== PinContentValidator::FIELDS) {
            $prompt .= "\n\nCURRENT PIN (keep consistent with it):\n".json_encode([
                'pin_title' => $current->title,
                'pin_description' => $current->description,
                'keywords' => $current->keywordList(),
            ], JSON_UNESCAPED_UNICODE);
            $prompt .= "\n\nWrite NEW, different values for: ".implode(', ', $fields).'. Other fields may repeat the current values.';
        }

        return $prompt;
    }

    /** @return list<string> */
    private function recentTitles(User $user, ?int $exceptPinId): array
    {
        return $user->pins()
            ->whereNotNull('title')
            ->when($exceptPinId, fn ($q) => $q->where('id', '!=', $exceptPinId))
            ->latest()
            ->limit(50)
            ->pluck('title')
            ->all();
    }

    /** @return list<string> */
    private function boardNames(User $user): array
    {
        return PinterestBoard::whereIn('pinterest_account_id', $user->pinterestAccounts()->pluck('id'))
            ->orderBy('name')
            ->limit(50)
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }
}
