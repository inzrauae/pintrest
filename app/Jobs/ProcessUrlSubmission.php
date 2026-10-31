<?php

namespace App\Jobs;

use App\Models\UrlSubmission;
use App\Services\Ai\AiException;
use App\Services\Fetching\FetchException;
use App\Services\Fetching\UnsafeUrlException;
use App\Services\PinGenerationService;
use App\Support\ActivityLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processes one URL. Failures are isolated to this URL so a batch keeps going.
 */
class ProcessUrlSubmission implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout = 300;

    /**
     * @param  bool  $retry  False for interactive (synchronous) runs: fail fast instead of releasing for a later retry.
     */
    public function __construct(public int $submissionId, public bool $retry = true)
    {
        $this->tries = (int) config('pinbot.processing.max_attempts');
        $this->onQueue('processing');
    }

    public function handle(PinGenerationService $service): void
    {
        $submission = UrlSubmission::with('batch')->find($this->submissionId);
        if (! $submission || in_array($submission->status, ['cancelled', 'generated', 'duplicate'], true)) {
            return;
        }

        $batch = $submission->batch;
        if ($batch?->status === 'cancelled') {
            $submission->update(['status' => 'cancelled']);

            return;
        }
        if ($batch?->status === 'paused') {
            $submission->update(['status' => 'paused']);

            return;
        }

        try {
            $service->process($submission);
        } catch (Throwable $e) {
            $retryable = match (true) {
                $e instanceof UnsafeUrlException => false,
                $e instanceof FetchException, $e instanceof AiException => $e->retryable,
                default => true,
            };

            $details = ['exception' => get_class($e), 'message' => $e->getMessage()] + ($e instanceof AiException ? $e->details : []);

            if ($this->retry && $retryable && $this->attempts() < $this->tries) {
                $delay = config('pinbot.processing.backoff')[$this->attempts() - 1] ?? 600;
                $submission->update(['status' => 'pending', 'error_message' => $e->getMessage().' Retrying…']);
                $this->release($delay);
            } else {
                $submission->update(['status' => 'failed', 'error_message' => $this->userMessage($e)]);
                ActivityLogger::log($submission->user_id, 'url_failed', "Processing failed for {$submission->url}: ".$this->userMessage($e), 'error', details: $details);
            }
        } finally {
            $batch?->refresh()->refreshCompletion();
        }
    }

    public function failed(?Throwable $e): void
    {
        UrlSubmission::whereKey($this->submissionId)
            ->whereNotIn('status', ['generated', 'cancelled'])
            ->update(['status' => 'failed', 'error_message' => 'Processing failed: '.($e?->getMessage() ?? 'unknown error')]);
    }

    private function userMessage(Throwable $e): string
    {
        return $e instanceof UnsafeUrlException || $e instanceof FetchException || $e instanceof AiException
            ? $e->getMessage()
            : 'An unexpected error occurred while processing this URL.';
    }
}
