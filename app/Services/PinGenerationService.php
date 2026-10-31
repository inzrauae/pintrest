<?php

namespace App\Services;

use App\Models\GeneratedPin;
use App\Models\UrlSubmission;
use App\Services\Ai\PinContentGenerator;
use App\Services\Extraction\PageAnalyzer;
use App\Services\Images\ImageCandidateRanker;
use App\Services\Images\PinImageService;
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
    ) {}

    public function process(UrlSubmission $submission): GeneratedPin
    {
        $submission->update(['status' => 'processing', 'error_message' => null, 'attempts' => $submission->attempts + 1]);

        $page = $this->analyzer->analyze($submission);
        $content = $this->generator->generate($submission, $page);
        $prefs = $submission->user->preferences();

        $pin = DB::transaction(function () use ($submission, $content, $prefs) {
            $template = $submission->user->templates()->where('is_default', true)->first();

            $pin = GeneratedPin::updateOrCreate(
                ['url_submission_id' => $submission->id],
                [
                    'user_id' => $submission->user_id,
                    'project_id' => $submission->project_id,
                    'pinterest_account_id' => $prefs->default_pinterest_account_id,
                    'board_id' => $prefs->default_board_id,
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
                ],
            );
            $pin->syncKeywords($content['keywords'], 'keyword');
            $pin->syncKeywords($content['suggested_hashtags'], 'hashtag');

            return $pin;
        });

        $warnings = $this->attachImages($pin, $page->images ?? [], $content['selected_image_index'], $submission->image_mode);

        $pin->update(['ai_metadata' => array_merge($pin->ai_metadata ?? [], ['warnings' => $warnings])]);
        $submission->update(['status' => 'generated', 'processed_at' => now()]);

        ActivityLogger::log($submission->user_id, 'pin_generated', "Pin generated for {$submission->url}", pin: $pin);

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
                ActivityLogger::log($pin->user_id, 'image_generation_failed', 'AI image generation failed.', 'warning', $pin, ['error' => $e->getMessage()]);
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

        return $pin->fresh(['images', 'keywords']);
    }
}
