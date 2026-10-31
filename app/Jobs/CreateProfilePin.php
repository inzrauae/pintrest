<?php

namespace App\Jobs;

use App\Models\UrlSubmission;
use App\Services\Seo\ProfileSeoService;
use App\Support\ActivityLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Writes a profile Pin in the background (AI copy, research and image take up to a minute or two),
 * so the web request returns at once and the single-threaded dev server never stalls.
 */
class CreateProfilePin implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $submissionId, public string $keyword)
    {
        $this->onQueue('processing');
    }

    public function handle(ProfileSeoService $profiles): void
    {
        $submission = UrlSubmission::with('user', 'extracted')->find($this->submissionId);
        if (! $submission || $submission->status !== 'pending') {
            return;
        }

        try {
            $pin = $profiles->finishProfilePin($submission, $this->keyword);
            ActivityLogger::log($submission->user_id, 'profile_pin_created', "Profile Pin created for “{$this->keyword}”.", pin: $pin);
        } catch (Throwable $e) {
            $submission->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            ActivityLogger::log($submission->user_id, 'url_failed', "Profile Pin for “{$this->keyword}” failed: ".$e->getMessage(), 'error');
        }
    }

    public function failed(?Throwable $e): void
    {
        UrlSubmission::whereKey($this->submissionId)->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'failed', 'error_message' => 'Profile Pin failed: '.($e?->getMessage() ?? 'unknown error')]);
    }
}
