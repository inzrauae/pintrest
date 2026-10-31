<?php

namespace App\Services\Seo;

use App\Models\GeneratedPin;
use App\Models\PinterestBoard;

/**
 * Scores a Pin's on-Pinterest SEO (0-100) with actionable checks.
 * A keyword "appears" in text when all its significant words do, so natural phrasing still counts.
 */
class SeoScorer
{
    public function __construct(private readonly KeywordResearchService $research) {}

    /**
     * @return array{score:int, focus_keyword:?string, checks:list<array{key:string, label:string, passed:bool, points:int, max:int, tip:string}>}
     */
    public function evaluate(GeneratedPin $pin): array
    {
        $title = (string) $pin->title;
        $description = (string) $pin->description;
        $researched = array_column($pin->researched_keywords ?? [], 'keyword');
        $own = $pin->relationLoaded('keywords') ? $pin->keywordList('keyword') : $pin->keywords()->where('type', 'keyword')->pluck('keyword')->all();
        $all = array_values(array_unique(array_merge($researched, $own)));
        $board = $this->boardName($pin);

        // Focus keyword: a real Pinterest search term used in the title, else the Pin's own top keyword.
        $focus = collect($researched)->first(fn ($k) => $this->contains($title, $k))
            ?? collect($own)->first(fn ($k) => $this->contains($title, $k))
            ?? ($researched[0] ?? $own[0] ?? null);

        $titleLen = mb_strlen($title);
        $descLen = mb_strlen($description);
        $inDescription = count(array_filter($all, fn ($k) => $this->contains($description, $k)));
        $focusPos = $focus ? $this->position($title, $focus) : null;

        $checks = [
            $this->check('title_length', 'Title is 40–100 characters', $titleLen >= 40 && $titleLen <= 100, 10,
                "Title has {$titleLen} characters. Aim for 40–100 so it is descriptive but not cut off."),
            $this->check('focus_in_title', 'Focus keyword in title', $focus !== null && $this->contains($title, $focus), 20,
                $focus ? "Work \"{$focus}\" into the title naturally." : 'Add keywords to this Pin first.'),
            $this->check('focus_early', 'Keyword near the start of the title', $focusPos !== null && $focusPos <= 40, 5,
                'Put the main keyword in the first 40 characters; Pinterest truncates long titles in the feed.'),
            $this->check('description_length', 'Description is 150–500 characters', $descLen >= 150 && $descLen <= 500, 15,
                "Description has {$descLen} characters. 150–500 gives Pinterest enough context."),
            $this->check('description_keywords', '3+ keywords in description', $inDescription >= 3, 15,
                "{$inDescription} keyword(s) found in the description. Use 3 or more naturally — no keyword lists.",
                min(15, $inDescription * 5)),
            $this->check('focus_in_description', 'Focus keyword in description', $focus !== null && $this->contains($description, $focus), 5,
                'Mention the focus keyword in the first sentence of the description.'),
            $this->check('alt_text', 'Alt text with a keyword', filled($pin->alt_text) && collect($all)->contains(fn ($k) => $this->contains((string) $pin->alt_text, $k)), 10,
                'Describe the image and include one keyword in the alt text.',
                filled($pin->alt_text) ? 5 : 0),
            $this->check('board', 'Board name matches a keyword', $board !== null && collect($all)->contains(fn ($k) => array_intersect($this->research->tokens($board), $this->research->tokens($k)) !== []), 10,
                $board ? "Board \"{$board}\" shares no words with this Pin's keywords. Use a keyword-rich board." : 'Choose a board whose name contains a main keyword.',
                $board ? 5 : 0),
        ];

        // Only scored when research ran, so users without API access are not penalised.
        if ($pin->researched_keywords !== null && $researched !== []) {
            $checks[] = $this->check('pinterest_terms', 'Uses real Pinterest search terms', collect($researched)->contains(fn ($k) => $this->contains($title.' '.$description, $k)), 10,
                'Use at least one researched Pinterest search term: '.implode(', ', array_slice($researched, 0, 3)).'.');
        }

        $max = array_sum(array_column($checks, 'max'));
        $earned = array_sum(array_column($checks, 'points'));

        return [
            'score' => $max ? (int) round($earned / $max * 100) : 0,
            'focus_keyword' => $focus ? mb_substr($focus, 0, 100) : null,
            'checks' => $checks,
        ];
    }

    /** Recomputes and stores the score without touching updated_at. */
    public function refresh(GeneratedPin $pin): array
    {
        $result = $this->evaluate($pin);
        GeneratedPin::whereKey($pin->id)->update(['seo_score' => $result['score'], 'focus_keyword' => $result['focus_keyword']]);
        $pin->forceFill(['seo_score' => $result['score'], 'focus_keyword' => $result['focus_keyword']])->syncOriginalAttributes(['seo_score', 'focus_keyword']);

        return $result;
    }

    public function contains(string $text, string $keyword): bool
    {
        $words = $this->research->tokens($keyword);

        return $words !== [] && array_diff($words, $this->research->tokens($text)) === [];
    }

    private function position(string $text, string $keyword): ?int
    {
        $first = $this->research->tokens($keyword)[0] ?? null;
        if (! $first) {
            return null;
        }
        $pos = mb_stripos($text, $first);

        return $pos === false ? null : $pos;
    }

    private function boardName(GeneratedPin $pin): ?string
    {
        if ($pin->board_id && $pin->pinterest_account_id) {
            $name = PinterestBoard::where('pinterest_account_id', $pin->pinterest_account_id)->where('board_id', $pin->board_id)->value('name');
            if ($name) {
                return $name;
            }
        }

        return $pin->suggested_board ?: null;
    }

    private function check(string $key, string $label, bool $passed, int $max, string $tip, int $partial = 0): array
    {
        return ['key' => $key, 'label' => $label, 'passed' => $passed, 'points' => $passed ? $max : min($partial, $max), 'max' => $max, 'tip' => $passed ? '' : $tip];
    }
}
