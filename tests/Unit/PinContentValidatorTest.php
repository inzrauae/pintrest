<?php

namespace Tests\Unit;

use App\Services\Ai\PinContentValidator;
use PHPUnit\Framework\TestCase;

class PinContentValidatorTest extends TestCase
{
    private function valid(): array
    {
        return [
            'pin_title' => 'How to Grow Basil Indoors on a Windowsill',
            'pin_description' => 'A practical guide to growing fresh basil indoors: light, watering and pruning tips for a steady harvest.',
            'keywords' => ['indoor basil', 'herb garden', 'windowsill herbs', 'indoor basil'],
            'suggested_hashtags' => ['basil', '#herbs'],
            'image_selection_reason' => 'Shows the plant.',
            'selected_image_index' => 0,
            'image_generation_prompt' => 'Basil on a windowsill',
            'suggested_board' => 'Gardening',
            'destination_url' => 'https://www.example.com/basil',
            'alt_text' => 'Basil plant in a pot',
        ];
    }

    public function test_valid_output_is_cleaned(): void
    {
        [$clean, $errors] = (new PinContentValidator)->validate($this->valid(), PinContentValidator::FIELDS, ['example.com'], 2);

        $this->assertSame([], $errors);
        $this->assertSame(['indoor basil', 'herb garden', 'windowsill herbs'], $clean['keywords'], 'duplicates removed');
        $this->assertSame(['#basil', '#herbs'], $clean['suggested_hashtags']);
    }

    public function test_rejects_invented_destination_and_bad_lengths(): void
    {
        $raw = array_merge($this->valid(), [
            'pin_title' => 'Short',
            'pin_description' => str_repeat('a', 600),
            'destination_url' => 'https://evil.test/landing',
            'selected_image_index' => 5,
            'keywords' => ['one'],
        ]);
        [, $errors] = (new PinContentValidator)->validate($raw, PinContentValidator::FIELDS, ['example.com'], 2);

        $joined = implode(' ', $errors);
        $this->assertStringContainsString('pin_title must be at least', $joined);
        $this->assertStringContainsString('at most 500', $joined);
        $this->assertStringContainsString('destination_url must point to the submitted page', $joined);
        $this->assertStringContainsString('selected_image_index', $joined);
        $this->assertStringContainsString('keywords must contain at least 3', $joined);
    }

    public function test_detects_keyword_stuffing_and_duplicate_titles(): void
    {
        $raw = array_merge($this->valid(), [
            'pin_description' => 'Basil basil basil basil basil basil basil basil recipes with basil and more basil basil.',
        ]);
        [, $errors] = (new PinContentValidator)->validate($raw, PinContentValidator::FIELDS, ['example.com'], 1, ['how to grow basil indoors on a windowsill']);

        $joined = implode(' ', $errors);
        $this->assertStringContainsString('keyword stuffing', $joined);
        $this->assertStringContainsString('duplicates an existing Pin title', $joined);
    }

    public function test_only_requested_fields_are_validated(): void
    {
        [$clean, $errors] = (new PinContentValidator)->validate(['pin_title' => 'A Brand New Distinct Title'], ['pin_title'], ['example.com'], 0);

        $this->assertSame([], $errors);
        $this->assertSame(['pin_title' => 'A Brand New Distinct Title'], $clean);
    }
}
