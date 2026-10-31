<?php

namespace App\Services\Pinterest;

use App\Models\GeneratedPin;
use App\Services\UsageLimiter;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Validates and publishes a Pin through POST /v5/pins.
 * Docs: https://developers.pinterest.com/docs/api/v5/pins-create/
 */
class PinPublisher
{
    public function __construct(
        private readonly PinterestClient $client,
        private readonly UsageLimiter $limiter,
    ) {}

    /**
     * Returns every reason the Pin cannot be published right now (empty = OK).
     *
     * @return list<string>
     */
    public function preflight(GeneratedPin $pin, bool $allowDuplicate = false, bool $checkLimit = true): array
    {
        $errors = [];
        $pin->loadMissing(['account', 'selectedImage']);

        if ($pin->status === 'published' || $pin->pinterest_pin_id) {
            return ['This Pin has already been published.'];
        }

        $account = $pin->account;
        if (! $account || $account->user_id !== $pin->user_id) {
            $errors[] = 'Select a connected Pinterest account.';
        } elseif ($account->status !== 'active') {
            $errors[] = 'The selected Pinterest account must be reconnected.';
        } elseif ($account->scopes && ! $account->hasScope('pins:write')) {
            $errors[] = 'The Pinterest account was connected without the pins:write permission. Reconnect it.';
        } elseif ($account->environment === 'production' && app(PinterestRateLimiter::class)->level() === 'trial') {
            // Pinterest refuses this with code 29; say so before trying.
            $errors[] = PinterestApiException::TRIAL_PRODUCTION_MESSAGE;
        }

        if (! $pin->board_id) {
            $errors[] = 'Select a destination board.';
        } elseif ($account && ! $account->boards()->where('board_id', $pin->board_id)->exists()) {
            $errors[] = 'The selected board does not belong to this Pinterest account. Refresh boards and select again.';
        }

        $title = trim((string) $pin->title);
        if ($title === '' || mb_strlen($title) > 100) {
            $errors[] = 'Title is required and must be at most 100 characters.';
        }
        if (mb_strlen((string) $pin->description) > 500) {
            $errors[] = 'Description must be at most 500 characters.';
        }
        if (! filter_var($pin->destination_url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $pin->destination_url)) {
            $errors[] = 'Destination URL must be a valid http(s) URL.';
        }

        $image = $pin->selectedImage;
        if (! $image || $image->status !== 'ready' || ! $image->path || ! Storage::disk(config('pinbot.images.disk'))->exists($image->path)) {
            $errors[] = 'Select a ready image for the Pin.';
        } else {
            if ($image->requiresRightsConfirmation() && ! $image->rights_confirmed) {
                $errors[] = 'Confirm you have the right to use the selected image from the source page.';
            }
            if ($image->size_bytes > 20 * 1024 * 1024) {
                $errors[] = 'The image exceeds Pinterest\'s 20 MB limit.';
            }
        }

        if (! $allowDuplicate && $this->duplicateOf($pin)) {
            $errors[] = 'A Pin for this destination URL was already published to the same board. Confirm to publish a duplicate.';
        }

        if ($checkLimit && ! $this->limiter->canPublish($pin->user)) {
            $limit = $this->limiter->dailyPublishLimit($pin->user);
            $max = app(PinterestRateLimiter::class)->maxDailyPublishes();
            $errors[] = "You have published {$this->limiter->publishedToday($pin->user)} of your {$limit} Pins for today. "
                .'This is your own limit in User Settings → Limits'.($limit < $max ? " (you can raise it up to {$max})" : '')
                .'; it resets at midnight in your timezone. Scheduled Pins wait until then.';
        }

        return $errors;
    }

    public function duplicateOf(GeneratedPin $pin): ?GeneratedPin
    {
        // Profile Pins all link to the same Pinterest profile on purpose (one per keyword), so they are never duplicates.
        if (($pin->ai_metadata['kind'] ?? null) === 'profile') {
            return null;
        }

        return GeneratedPin::where('user_id', $pin->user_id)
            ->where('id', '!=', $pin->id)
            ->where('destination_hash', $pin->destination_hash)
            ->where('board_id', $pin->board_id)
            ->whereIn('status', ['published', 'publishing'])
            ->first();
    }

    /**
     * Publishes the Pin. A per-Pin lock plus the pinterest_pin_id check guarantee a Pin is
     * never created twice, even if two workers pick it up.
     */
    public function publish(GeneratedPin $pin): GeneratedPin
    {
        $lock = Cache::lock("publish_pin:{$pin->id}", (int) config('pinbot.publishing.lock_seconds'));
        if (! $lock->get()) {
            throw new PinterestApiException('This Pin is already being published.', retryable: true, retryAfter: 60);
        }

        try {
            $pin->refresh()->load(['account', 'selectedImage']);
            if ($pin->pinterest_pin_id) {
                return $pin;
            }

            $image = $pin->selectedImage;
            $bytes = Storage::disk(config('pinbot.images.disk'))->get($image->path);

            $payload = array_filter([
                'board_id' => $pin->board_id,
                'title' => Str::limit((string) $pin->title, 100, ''),
                'description' => Str::limit($this->descriptionWithHashtags($pin), 500, ''),
                'link' => $pin->destination_url,
                'alt_text' => $pin->alt_text ? Str::limit($pin->alt_text, 500, '') : null,
                'media_source' => [
                    'source_type' => 'image_base64',
                    'content_type' => $image->mime ?: 'image/jpeg',
                    'data' => base64_encode($bytes),
                ],
            ]);

            $result = $this->client->post($pin->account, 'pins', $payload);

            $pin->update([
                'status' => 'published',
                'pinterest_pin_id' => $result['id'] ?? null,
                'published_at' => now(),
                'error_message' => null,
            ]);
            $pin->schedule?->update(['status' => 'published']);

            ActivityLogger::log($pin->user_id, 'pin_published', 'Published "'.Str::limit($pin->title, 60).'" to Pinterest.', pin: $pin, details: ['pinterest_pin_id' => $result['id'] ?? null]);

            return $pin;
        } finally {
            $lock->release();
        }
    }

    private function descriptionWithHashtags(GeneratedPin $pin): string
    {
        $description = trim((string) $pin->description);
        $tags = collect($pin->keywordList('hashtag'))
            ->reject(fn ($t) => str_contains(mb_strtolower($description), mb_strtolower($t)))
            ->implode(' ');

        return $tags !== '' && mb_strlen($description.' '.$tags) <= 500 ? $description."\n\n".$tags : $description;
    }

    public function pinUrl(GeneratedPin $pin): ?string
    {
        return $pin->pinterest_pin_id ? 'https://www.pinterest.com/pin/'.$pin->pinterest_pin_id.'/' : null;
    }
}
