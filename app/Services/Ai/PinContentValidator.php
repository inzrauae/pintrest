<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

/**
 * Validates and normalises the AI's structured output. Returns a list of human-readable
 * problems which are fed back to the model on retry.
 */
class PinContentValidator
{
    public const FIELDS = [
        'pin_title', 'pin_description', 'keywords', 'suggested_hashtags', 'image_selection_reason',
        'selected_image_index', 'image_generation_prompt', 'suggested_board', 'destination_url', 'alt_text',
    ];

    /**
     * @param  list<string>  $fields  Fields that must be valid (others are ignored).
     * @param  list<string>  $allowedHosts  Hosts the destination URL may point at.
     * @param  list<string>  $avoidTitles  Existing titles that must not be repeated.
     * @return array{0: array, 1: list<string>} [clean data, errors]
     */
    public function validate(array $raw, array $fields, array $allowedHosts, int $imageCount, array $avoidTitles = []): array
    {
        $clean = [];
        $errors = [];
        $text = fn ($v) => is_string($v) ? trim(preg_replace('/\s+/u', ' ', strip_tags($v))) : null;

        foreach ($fields as $field) {
            if (! array_key_exists($field, $raw)) {
                $errors[] = "Missing field \"{$field}\".";
            }
        }

        if (in_array('pin_title', $fields, true)) {
            $title = $text($raw['pin_title'] ?? null);
            if (! $title || mb_strlen($title) < 10) {
                $errors[] = 'pin_title must be at least 10 characters.';
            } elseif (mb_strlen($title) > 100) {
                $errors[] = 'pin_title must be at most 100 characters.';
            } elseif (in_array(mb_strtolower($title), array_map('mb_strtolower', $avoidTitles), true)) {
                $errors[] = 'pin_title duplicates an existing Pin title; write a distinct title.';
            } elseif (preg_match('/#\w/u', $title)) {
                $errors[] = 'pin_title must not contain hashtags.';
            }
            $clean['pin_title'] = $title;
        }

        if (in_array('pin_description', $fields, true)) {
            $description = $text($raw['pin_description'] ?? null);
            if (! $description || mb_strlen($description) < 50) {
                $errors[] = 'pin_description must be at least 50 characters.';
            } elseif (mb_strlen($description) > 500) {
                $errors[] = 'pin_description must be at most 500 characters.';
            } elseif ($this->isStuffed($description)) {
                $errors[] = 'pin_description repeats the same words too often (keyword stuffing). Write natural sentences.';
            }
            $clean['pin_description'] = $description;
        }

        foreach (['keywords' => [3, 15], 'suggested_hashtags' => [0, 8]] as $field => [$min, $max]) {
            if (! in_array($field, $fields, true)) {
                continue;
            }
            $list = collect(is_array($raw[$field] ?? null) ? $raw[$field] : [])
                ->map($text)
                ->filter(fn ($k) => $k && mb_strlen($k) <= 60)
                ->map(fn ($k) => $field === 'suggested_hashtags' ? '#'.ltrim(preg_replace('/\s+/u', '', $k), '#') : $k)
                ->unique(fn ($k) => mb_strtolower($k))
                ->values()
                ->all();
            if (count($list) < $min) {
                $errors[] = "{$field} must contain at least {$min} items.";
            }
            $clean[$field] = array_slice($list, 0, $max);
        }

        if (in_array('destination_url', $fields, true)) {
            $url = trim((string) ($raw['destination_url'] ?? ''));
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! preg_match('#^https?://#i', $url) || ! $host) {
                $errors[] = 'destination_url must be an absolute http(s) URL.';
            } elseif (! $this->hostAllowed($host, $allowedHosts)) {
                $errors[] = 'destination_url must point to the submitted page (allowed hosts: '.implode(', ', $allowedHosts).').';
            }
            $clean['destination_url'] = $url;
        }

        if (in_array('selected_image_index', $fields, true)) {
            $index = $raw['selected_image_index'] ?? -1;
            if (! is_int($index) || $index < -1 || $index >= $imageCount) {
                $errors[] = 'selected_image_index must be -1 or an index of the provided images (0..'.max(0, $imageCount - 1).').';
            }
            $clean['selected_image_index'] = is_int($index) ? $index : -1;
        }

        foreach (['image_selection_reason' => 1000, 'image_generation_prompt' => 1500, 'suggested_board' => 100, 'alt_text' => 500] as $field => $limit) {
            if (in_array($field, $fields, true)) {
                $clean[$field] = Str::limit((string) $text($raw[$field] ?? ''), $limit, '');
            }
        }

        return [$clean, $errors];
    }

    private function hostAllowed(string $host, array $allowed): bool
    {
        $strip = fn ($h) => preg_replace('/^www\./', '', strtolower($h));
        foreach ($allowed as $candidate) {
            if ($strip($host) === $strip($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function isStuffed(string $text): bool
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $counts = array_count_values(array_filter($words, fn ($w) => mb_strlen($w) > 4));

        return $counts !== [] && max($counts) > max(4, count($words) * 0.12);
    }
}
