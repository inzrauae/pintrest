<?php

namespace App\Services\Images;

use App\Models\GeneratedPin;
use App\Models\PinImage;
use App\Services\Fetching\SafeHttpClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Adds images to a Pin from the source page, an upload, or AI generation, and manages selection.
 */
class PinImageService
{
    public const PINTEREST_FORMAT = 'Pinterest pin image: vertical portrait, 2:3 aspect ratio (1000x1500), full-bleed edge to edge with no borders or frames. '
        .'Main subject large, centred and in sharp focus, readable at small mobile-feed size. Bright, high-contrast, professional photographic quality. '
        .'Keep the top and bottom fifth uncluttered so a headline can be overlaid later.';

    public function __construct(
        private readonly SafeHttpClient $http,
        private readonly ImageProcessor $processor,
        private readonly ImageGeneratorFactory $generators,
    ) {}

    public function importFromUrl(GeneratedPin $pin, string $url, int $score = 0): PinImage
    {
        $result = $this->http->get($url, ['image/*'], (int) config('pinbot.fetch.max_image_bytes'));

        return $this->store($pin, $result->body, 'extracted', $result->finalUrl, $score);
    }

    public function importUpload(GeneratedPin $pin, UploadedFile $file): PinImage
    {
        $image = $this->store($pin, (string) file_get_contents($file->getRealPath()), 'uploaded', null, 100);
        // The user supplied this file themselves.
        $image->update(['rights_confirmed' => true]);

        return $image;
    }

    public function generate(GeneratedPin $pin, ?string $prompt = null): PinImage
    {
        $settings = $pin->user->imageSettingsOrDefault();
        $custom = trim((string) $prompt);
        // By default the image illustrates the Pin's current copy, so it stays in step with edits to the description.
        $prompt = $custom !== '' ? $custom : $this->promptFromCopy($pin);
        if ($style = $pin->template?->image_style) {
            $prompt .= ' Style: '.$style;
        }
        // Text is added by templates when requested, never baked in by the model.
        if (! str_contains(strtolower($prompt), 'no text')) {
            $prompt .= ' Do not include any text, letters or logos in the image.';
        }

        // Format direction is sent with every request but not saved, so it never piles up on regeneration.
        $bytes = $this->generators->for($settings->provider)->generate(self::PINTEREST_FORMAT.' '.$prompt, $settings);
        $image = $this->store($pin, $bytes, 'generated', null, 80);
        $image->update(['rights_confirmed' => true]);
        if ($custom !== '') {
            $pin->update(['image_generation_prompt' => mb_substr($custom, 0, 2000)]);
        }

        return $image;
    }

    private function promptFromCopy(GeneratedPin $pin): string
    {
        $prompt = 'Create an image that visually illustrates this Pinterest Pin. Title: "'.$pin->title.'".';
        if (filled($pin->description)) {
            $prompt .= ' Description: "'.$pin->description.'".';
        }

        // Screens and dashboards are mostly text, which the model renders as gibberish.
        return $prompt.' Show the idea as a real-world scene or concept, not as a screenshot, dashboard or user-interface mockup. No text.';
    }

    public function select(GeneratedPin $pin, PinImage $image): void
    {
        DB::transaction(function () use ($pin, $image) {
            $pin->images()->where('id', '!=', $image->id)->update(['is_selected' => false]);
            $image->update(['is_selected' => true]);
        });
        $pin->unsetRelation('selectedImage');
    }

    /** Re-renders with a new crop (fractions of the original) and the Pin's template overlay. */
    public function recrop(PinImage $image, ?array $crop): PinImage
    {
        $image->crop = $crop;

        return $this->processor->render($image, $image->pin->template, $image->pin->title);
    }

    /** Re-renders every image of a Pin, e.g. after its template or title changed. */
    public function rerender(GeneratedPin $pin): void
    {
        foreach ($pin->images()->where('status', 'ready')->get() as $image) {
            $this->processor->render($image, $pin->template, $pin->title);
        }
    }

    public function delete(PinImage $image): void
    {
        $disk = Storage::disk($this->processor->disk());
        $disk->delete(array_filter([$image->path, $image->original_path]));
        $wasSelected = $image->is_selected;
        $pin = $image->pin;
        $image->delete();

        if ($wasSelected && ($next = $pin->images()->where('status', 'ready')->orderByDesc('score')->first())) {
            $this->select($pin, $next);
        }
    }

    private function store(GeneratedPin $pin, string $bytes, string $source, ?string $sourceUrl, int $score): PinImage
    {
        [$mime] = $this->processor->inspect($bytes);
        $original = $this->processor->storeOriginal($bytes, $pin->user_id, $pin->id, $mime);

        $image = $pin->images()->create([
            'source' => $source,
            'source_url' => $sourceUrl,
            'original_path' => $original,
            'score' => $score,
            'status' => 'ready',
        ]);

        $this->processor->render($image, $pin->template, $pin->title);

        if (! $pin->images()->where('is_selected', true)->exists()) {
            $this->select($pin, $image);
        }

        return $image->refresh();
    }
}
