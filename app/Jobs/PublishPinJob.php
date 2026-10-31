<?php

namespace App\Jobs;

use App\Models\PublishingJob;
use App\Services\Pinterest\PinPublisher;
use App\Services\Pinterest\PinterestApiException;
use App\Support\ActivityLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PublishPinJob implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout = 120;

    public function __construct(public int $publishingJobId, public bool $allowDuplicate = false)
    {
        $this->tries = (int) config('pinbot.publishing.max_attempts');
        $this->onQueue('publishing');
    }

    public function handle(PinPublisher $publisher): void
    {
        $job = PublishingJob::with('pin.user')->find($this->publishingJobId);
        if (! $job || $job->status === 'cancelled' || ! $job->pin) {
            return;
        }
        $pin = $job->pin;

        if ($pin->status === 'published') {
            $job->update(['status' => 'succeeded', 'finished_at' => now()]);

            return;
        }

        $job->update(['status' => 'running', 'attempts' => $this->attempts(), 'started_at' => $job->started_at ?? now()]);

        // Conditions may have changed since queueing (account disconnected, image deleted...).
        // The daily limit was already enforced when the Pin was queued.
        $errors = $publisher->preflight($pin, $this->allowDuplicate, checkLimit: false);
        if ($errors) {
            $this->failPermanently($job, implode(' ', $errors), ['preflight' => $errors]);

            return;
        }

        try {
            $publisher->publish($pin);
            $job->update(['status' => 'succeeded', 'finished_at' => now(), 'last_error' => null]);
        } catch (PinterestApiException $e) {
            ActivityLogger::log(
                $pin->user_id, 'publish_error', $e->getMessage(), $e->retryable ? 'warning' : 'error', $pin,
                $e->developerDetails() + ['attempt' => $this->attempts()], $e->status,
            );

            if ($e->retryable && $this->attempts() < $this->tries) {
                $delay = $e->retryAfter ?? $this->backoffFor($this->attempts());
                $job->update(['status' => 'queued', 'last_error' => $e->getMessage(), 'next_attempt_at' => now()->addSeconds($delay)]);
                $pin->update(['error_message' => $e->getMessage().' Retrying…']);
                $this->release($delay);

                return;
            }

            $this->failPermanently($job, $e->getMessage(), $e->developerDetails());
        }
    }

    public function failed(?Throwable $e): void
    {
        $job = PublishingJob::with('pin')->find($this->publishingJobId);
        if ($job && $job->status !== 'failed') {
            $this->failPermanently($job, 'Publishing failed due to an internal error.', ['exception' => $e?->getMessage()]);
        }
    }

    private function failPermanently(PublishingJob $job, string $message, array $details): void
    {
        $job->update(['status' => 'failed', 'last_error' => $message, 'finished_at' => now()]);
        $job->pin->update(['status' => 'failed', 'error_message' => $message]);
        $job->pin->schedule?->update(['status' => 'failed']);

        ActivityLogger::log($job->user_id, 'publish_failed', 'Publishing failed: '.$message, 'error', $job->pin, $details);
    }

    private function backoffFor(int $attempt): int
    {
        $steps = config('pinbot.publishing.backoff');

        return $steps[min($attempt - 1, count($steps) - 1)];
    }
}
