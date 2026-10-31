<?php

namespace App\Services\Ai;

use App\Models\AiProviderSetting;
use Illuminate\Support\Str;

/**
 * Deterministic, network-free provider. It only re-uses text that exists on the source
 * page, so it never invents claims. Used for local development without an API key and in tests.
 */
class OfflineLlmClient implements LlmClient
{
    private const STOPWORDS = ['the', 'and', 'for', 'with', 'that', 'this', 'from', 'your', 'you', 'are', 'how', 'what',
        'our', 'was', 'has', 'have', 'will', 'can', 'all', 'not', 'but', 'its', 'into', 'about', 'more', 'their', 'they',
        'when', 'which', 'who', 'why', 'out', 'use', 'new', 'one', 'get', 'also', 'than', 'then', 'them', 'these', 'those'];

    public function generateJson(string $system, string $user, array $schema, array $context, AiProviderSetting $settings): array
    {
        $page = $context['page'] ?? [];
        $baseTitle = $page['og_title'] ?? $page['title'] ?? 'Untitled page';
        $baseTitle = trim(preg_split('/\s+[|\-–—]\s+/u', $baseTitle)[0] ?? $baseTitle);

        $title = $baseTitle;
        $variant = (int) ($context['variant'] ?? 0);
        if ($variant > 0) {
            $title = Str::limit($title, 80, '').' – '.['Ideas & Inspiration', 'A Closer Look', 'Worth Saving', 'Full Guide'][$variant % 4];
        }

        // Body text without the extractor's "[Structured data]" preamble.
        $body = trim((string) preg_replace('/^\[Structured data\].*?\n\n/s', '', (string) ($page['content'] ?? '')));
        $description = $page['og_description'] ?? $page['meta_description'] ?? Str::limit($body, 300);
        $description = Str::limit(trim((string) $description), 380, '…');
        if (mb_strlen($description) < 50) {
            $description = trim($description.' Discover more about '.$title.'.');
        }

        $keywords = $this->keywords($baseTitle.' '.($page['meta_description'] ?? '').' '.collect($page['headings'] ?? [])->pluck('text')->implode(' '));

        // Researched Pinterest searches lead the keyword list, as the prompt asks real models to do.
        $keywords = array_values(array_unique(array_merge(array_column(array_slice($context['research'] ?? [], 0, 4), 'keyword'), $keywords)));

        $images = $context['images'] ?? [];

        return [
            'pin_title' => Str::limit($title, 100, ''),
            'pin_description' => $description,
            'keywords' => array_slice($keywords, 0, 8) ?: ['inspiration', 'ideas', 'tips'],
            'suggested_hashtags' => array_map(fn ($k) => '#'.Str::studly($k), array_slice($keywords, 0, 4)),
            'image_selection_reason' => $images ? 'Chose the primary page image (Open Graph / largest) because it best represents the content.' : 'The page has no usable image.',
            'selected_image_index' => $images ? 0 : -1,
            'image_generation_prompt' => 'Vertical 2:3 Pinterest image illustrating: '.$title.'. Clean, bright, editorial style, no text.',
            'suggested_board' => $context['boards'][0] ?? Str::title($keywords[0] ?? 'Ideas'),
            'destination_url' => $context['destination_url'] ?? ($page['canonical_url'] ?? $page['final_url'] ?? ''),
            'alt_text' => Str::limit('Image for '.$title, 480, ''),
        ];
    }

    /** @return list<string> */
    private function keywords(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $counts = [];
        foreach ($words as $w) {
            if (mb_strlen($w) > 3 && ! in_array($w, self::STOPWORDS, true) && ! is_numeric($w)) {
                $counts[$w] = ($counts[$w] ?? 0) + 1;
            }
        }
        arsort($counts);

        return array_keys(array_slice($counts, 0, 10, true));
    }
}
