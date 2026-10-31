<?php

namespace App\Services;

use App\Models\ExtractedPageData;
use App\Models\GeneratedPin;
use App\Models\UrlSubmission;
use App\Services\Ai\AiException;
use App\Services\Ai\PinContentGenerator;
use App\Services\Extraction\PageAnalyzer;
use App\Services\Images\ImageCandidateRanker;
use App\Services\Images\PinImageService;
use App\Services\Seo\SeoScorer;
use App\Services\Seo\TopicAutopilot;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * URL submission → extracted page → AI content → draft Pin with images.
 */
class PinGenerationService
{
    /** How many extracted candidates to download so the user has alternatives. */
    private const EXTRACTED_IMAGES_TO_IMPORT = 4;

    public function __construct(
        private readonly PageAnalyzer $analyzer,
        private readonly PinContentGenerator $generator,
        private readonly ImageCandidateRanker $ranker,
        private readonly PinImageService $images,
        private readonly SeoScorer $seo,
    ) {}

    public function process(UrlSubmission $submission): GeneratedPin
    {
        $submission->update(['status' => 'processing', 'error_message' => null, 'attempts' => $submission->attempts + 1]);

        return $this->processPage($submission, $this->analyzer->analyze($submission));
    }

    /**
     * Builds the Pin from page data that is already stored (fetched by the analyzer, or written directly,
     * e.g. for profile Pins whose "page" is the user's Pinterest profile).
     */
    public function processPage(UrlSubmission $submission, ExtractedPageData $page): GeneratedPin
    {
        $content = $this->generator->generate($submission, $page);
        $prefs = $submission->user->preferences();

        $pin = DB::transaction(function () use ($submission, $content, $prefs) {
            // A batch can pick its own design template; otherwise the user's default applies.
            $templateId = $submission->batch?->options['template_id'] ?? null;
            $template = ($templateId ? $submission->user->templates()->find($templateId) : null)
                ?? $submission->user->templates()->where('is_default', true)->first();

            $pin = GeneratedPin::updateOrCreate(
                ['url_submission_id' => $submission->id],
                [
                    'user_id' => $submission->user_id,
                    'project_id' => $submission->project_id,
                    // The board picked when submitting wins over the user's default board.
                    'pinterest_account_id' => $submission->pinterest_account_id ?? $prefs->default_pinterest_account_id,
                    'board_id' => $submission->pinterest_account_id ? $submission->board_id : $prefs->default_board_id,
                    'pin_template_id' => $template?->id,
                    'title' => $content['pin_title'],
                    'description' => $content['pin_description'],
                    'alt_text' => $content['alt_text'] ?: null,
                    'destination_url' => $content['destination_url'],
                    'language' => $submission->language,
                    'suggested_board' => $content['suggested_board'] ?: null,
                    'image_selection_reason' => $content['image_selection_reason'] ?: null,
                    'image_generation_prompt' => $content['image_generation_prompt'] ?: null,
                    'status' => 'draft',
                    'error_message' => null,
                    'ai_metadata' => $content['_meta'] + ['warnings' => []],
                    'researched_keywords' => $content['_research'],
                ],
            );
            $pin->syncKeywords($content['keywords'], 'keyword');
            $pin->syncKeywords($content['suggested_hashtags'], 'hashtag');

            return $pin;
        });

        $warnings = $this->attachImages($pin, $page->images ?? [], $content['selected_image_index'], $submission->image_mode);

        $pin->update(['ai_metadata' => array_merge($pin->ai_metadata ?? [], ['warnings' => $warnings])]);
        $submission->update(['status' => 'generated', 'processed_at' => now()]);
        $this->seo->refresh($pin->fresh('keywords'));

        ActivityLogger::log($submission->user_id, 'pin_generated', "Pin generated for {$submission->url}", pin: $pin);

        // Pins made by a topic's autopilot go to the topic's board and may be auto-approved.
        $topicId = $submission->batch?->options['topic_id'] ?? null;
        if ($topicId && ($topic = $submission->user->seoTopics()->find($topicId))) {
            app(TopicAutopilot::class)->applyToPin($pin->fresh(), $topic);
        }

        return $pin->fresh(['images', 'keywords']);
    }

    /** @return list<string> warnings */
    private function attachImages(GeneratedPin $pin, array $candidates, int $aiIndex, string $mode): array
    {
        $warnings = [];
        $pin->images()->each(fn ($img) => $this->images->delete($img));

        if (in_array($mode, ['extract', 'both'], true)) {
            $imported = 0;
            foreach ($this->ranker->rank($candidates, $aiIndex >= 0 ? $aiIndex : null) as $candidate) {
                if ($imported >= self::EXTRACTED_IMAGES_TO_IMPORT) {
                    break;
                }
                try {
                    $this->images->importFromUrl($pin, $candidate['url'], $candidate['score']);
                    $imported++;
                } catch (Throwable $e) {
                    // One bad image must not fail the Pin; try the next candidate.
                    continue;
                }
            }
            if ($imported === 0) {
                $warnings[] = 'No usable image was found on the page. Generate an AI image or upload your own.';
            }
        }

        if (in_array($mode, ['generate', 'both'], true)) {
            try {
                $this->images->generate($pin);
            } catch (Throwable $e) {
                $warnings[] = 'AI image generation failed: '.$e->getMessage();
                ActivityLogger::log($pin->user_id, 'image_generation_failed', 'AI image generation failed: '.$e->getMessage(), 'warning', $pin, ['error' => $e->getMessage()] + ($e instanceof AiException ? $e->details : []));
            }
        }

        // In "both" mode prefer the page's own image when there is one.
        if ($mode === 'both' && ($extracted = $pin->images()->where('source', 'extracted')->orderByDesc('score')->first())) {
            $this->images->select($pin, $extracted);
        }

        return $warnings;
    }

    /**
     * Regenerates only the requested fields of an existing Pin.
     *
     * @param  list<string>  $fields  Subset of: title, description, keywords, hashtags, alt_text, image_prompt
     */
    public function regenerate(GeneratedPin $pin, array $fields): GeneratedPin
    {
        $map = [
            'title' => 'pin_title',
            'description' => 'pin_description',
            'keywords' => 'keywords',
            'hashtags' => 'suggested_hashtags',
            'alt_text' => 'alt_text',
            'image_prompt' => 'image_generation_prompt',
            'board' => 'suggested_board',
        ];
        $aiFields = array_values(array_intersect_key($map, array_flip($fields)));

        $submission = $pin->submission;
        if (! $submission || ! $submission->extracted) {
            throw new \RuntimeException('The source page data for this Pin is no longer available.');
        }

        $content = $this->generator->generate($submission, $submission->extracted, $aiFields, $pin);

        $updates = array_filter([
            'title' => $content['pin_title'] ?? null,
            'description' => $content['pin_description'] ?? null,
            'alt_text' => $content['alt_text'] ?? null,
            'image_generation_prompt' => $content['image_generation_prompt'] ?? null,
            'suggested_board' => $content['suggested_board'] ?? null,
        ], fn ($v) => $v !== null);
        $pin->update($updates);

        if (isset($content['keywords'])) {
            $pin->syncKeywords($content['keywords'], 'keyword');
        }
        if (isset($content['suggested_hashtags'])) {
            $pin->syncKeywords($content['suggested_hashtags'], 'hashtag');
        }

        if (isset($updates['title']) && $pin->template?->text_overlay) {
            $this->images->rerender($pin);
        }
        if (! $pin->researched_keywords && ($content['_research'] ?? null) !== null) {
            $pin->update(['researched_keywords' => $content['_research']]);
        }
        $this->seo->refresh($pin->fresh('keywords'));

        return $pin->fresh(['images', 'keywords']);
    }
}
