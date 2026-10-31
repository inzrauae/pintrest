<?php

namespace App\Services;

use App\Jobs\ProcessUrlSubmission;
use App\Models\UrlBatch;
use App\Models\UrlSubmission;
use App\Models\User;
use App\Services\Fetching\SafeHttpClient;
use App\Services\Fetching\UnsafeUrlException;
use App\Support\UrlNormalizer;
use Illuminate\Validation\ValidationException;

class UrlSubmissionService
{
    public function __construct(
        private readonly SafeHttpClient $http,
        private readonly UsageLimiter $limiter,
    ) {}

    /** Most recent previous submission of the same normalized URL by this user, if any. */
    public function findDuplicate(User $user, string $url): ?UrlSubmission
    {
        return $user->urlSubmissions()
            ->where('url_hash', UrlNormalizer::hash($url))
            ->whereNotIn('status', ['failed', 'cancelled', 'duplicate'])
            ->with('pin')
            ->latest()
            ->first();
    }

    public function create(User $user, string $url, array $options, ?UrlBatch $batch = null): UrlSubmission
    {
        $prefs = $user->preferences();

        return $user->urlSubmissions()->create([
            'project_id' => $options['project_id'] ?? $batch?->project_id,
            'url_batch_id' => $batch?->id,
            'url' => $url,
            'normalized_url' => UrlNormalizer::normalize($url),
            'url_hash' => UrlNormalizer::hash($url),
            'language' => $options['language'] ?? $prefs->language,
            'image_mode' => $options['image_mode'] ?? $prefs->image_mode,
            'status' => 'pending',
        ]);
    }

    public function assertWithinLimits(User $user, int $count = 1): void
    {
        $remaining = $this->limiter->remainingProcessing($user);
        if ($remaining < $count) {
            throw ValidationException::withMessages([
                'limit' => $remaining === 0
                    ? 'You have reached your hourly or daily processing limit. Adjust it in Settings or try later.'
                    : "Only {$remaining} more URL(s) can be processed right now under your hourly/daily limits.",
            ]);
        }
    }

    /**
     * Creates a bulk batch. Every URL is validated independently; invalid or duplicate URLs are
     * recorded with a status instead of failing the batch.
     *
     * @param  list<string>  $urls
     */
    public function createBatch(User $user, array $urls, array $options): UrlBatch
    {
        $urls = array_values(array_unique(array_filter(array_map('trim', $urls))));
        $max = (int) config('pinbot.processing.max_bulk_urls');
        if (count($urls) > $max) {
            throw ValidationException::withMessages(['urls' => "A batch can contain at most {$max} URLs."]);
        }
        if ($urls === []) {
            throw ValidationException::withMessages(['urls' => 'No URLs were provided.']);
        }

        $batch = $user->urlBatches()->create([
            'project_id' => $options['project_id'] ?? null,
            'name' => $options['name'] ?? ('Batch '.now()->format('Y-m-d H:i')),
            'status' => 'processing',
            'options' => array_intersect_key($options, array_flip(['language', 'image_mode', 'skip_duplicates'])),
            'total' => count($urls),
        ]);

        $quota = $this->limiter->remainingProcessing($user);
        $seen = [];

        foreach ($urls as $url) {
            $submission = $this->create($user, $url, $options, $batch);
            $hash = $submission->url_hash;

            $error = $this->validationError($url);
            if ($error) {
                $submission->update(['status' => 'failed', 'error_message' => $error]);

                continue;
            }

            if (isset($seen[$hash]) || (($options['skip_duplicates'] ?? true) && $this->findDuplicateExcept($user, $hash, $submission->id))) {
                $submission->update(['status' => 'duplicate', 'error_message' => 'This URL was already submitted.']);

                continue;
            }
            $seen[$hash] = true;

            if ($quota <= 0) {
                // Over quota: keep it paused so the user can resume it later.
                $submission->update(['status' => 'paused', 'error_message' => 'Waiting: processing limit reached. Resume the batch later.']);

                continue;
            }
            $quota--;

            ProcessUrlSubmission::dispatch($submission->id);
        }

        $batch->refreshCompletion();

        return $batch;
    }

    private function findDuplicateExcept(User $user, string $hash, int $exceptId): bool
    {
        return $user->urlSubmissions()
            ->where('url_hash', $hash)
            ->where('id', '!=', $exceptId)
            ->whereNotIn('status', ['failed', 'cancelled', 'duplicate'])
            ->exists();
    }

    /** Syntactic + network-safety validation without fetching. Returns an error message or null. */
    public function validationError(string $url): ?string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url)) {
            return 'Invalid URL. Use a full http:// or https:// address.';
        }

        try {
            $this->http->assertSafe($url);
        } catch (UnsafeUrlException $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }
}
