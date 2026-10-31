<?php

namespace Tests\Unit;

use App\Services\Images\ImageCandidateRanker;
use PHPUnit\Framework\TestCase;

class ImageCandidateRankerTest extends TestCase
{
    public function test_prefers_og_image_and_vertical_large_images(): void
    {
        $ranked = (new ImageCandidateRanker)->rank([
            ['url' => 'a', 'source' => 'img', 'width' => 800, 'height' => 400],
            ['url' => 'b', 'source' => 'img', 'width' => 1000, 'height' => 1500, 'alt' => 'nice'],
            ['url' => 'c', 'source' => 'og:image'],
        ]);

        $this->assertSame('b', $ranked[0]['url']);
        $this->assertSame('a', $ranked[2]['url']);
    }

    public function test_ai_selection_gets_a_boost(): void
    {
        $ranked = (new ImageCandidateRanker)->rank([
            ['url' => 'og', 'source' => 'og:image'],
            ['url' => 'picked', 'source' => 'img'],
        ], aiSelectedIndex: 1);

        $this->assertSame('picked', $ranked[0]['url']);
    }
}
