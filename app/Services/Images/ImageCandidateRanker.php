<?php

namespace App\Services\Images;

/**
 * Scores extracted page images for suitability as a Pinterest Pin image.
 */
class ImageCandidateRanker
{
    private const SOURCE_SCORES = ['og:image' => 40, 'json-ld' => 30, 'twitter:image' => 25, 'image_src' => 20, 'img' => 10];

    /**
     * @param  list<array>  $images  From MetadataExtractor.
     * @return list<array> Same items plus "index" and "score", best first.
     */
    public function rank(array $images, ?int $aiSelectedIndex = null): array
    {
        $ranked = [];
        foreach (array_values($images) as $i => $img) {
            $score = self::SOURCE_SCORES[$img['source'] ?? 'img'] ?? 10;
            $w = $img['width'] ?? null;
            $h = $img['height'] ?? null;

            if ($w && $h) {
                // Closeness to the 2:3 Pinterest ratio (0.667) — vertical images win.
                $ratio = $w / $h;
                $score += (int) round(max(0, 30 - abs($ratio - 2 / 3) * 40));
                $score += $w >= 1000 ? 20 : ($w >= 600 ? 10 : 0);
            }
            if (! empty($img['alt'])) {
                $score += 5;
            }
            if ($aiSelectedIndex !== null && $aiSelectedIndex === $i) {
                $score += 50;
            }

            $ranked[] = $img + ['index' => $i, 'score' => min($score, 999)];
        }

        usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $ranked;
    }
}
