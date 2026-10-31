<?php

namespace App\Console\Commands;

use App\Models\UrlBatch;
use App\Services\UrlSubmissionService;
use Illuminate\Console\Command;

/**
 * Continues batches whose URLs were held back by the processing limit, so a large sitemap
 * finishes on its own across hours/days. Batches the user paused or cancelled are left alone.
 */
class ResumeWaitingSubmissions extends Command
{
    protected $signature = 'pins:resume-waiting';

    protected $description = 'Queue URLs that were waiting for processing quota';

    public function handle(UrlSubmissionService $submissions): int
    {
        $batches = UrlBatch::with('user')
            ->whereNotIn('status', ['paused', 'cancelled'])
            ->whereHas('submissions', fn ($q) => $q->where('status', 'paused')->where('error_message', 'like', 'Waiting:%'))
            ->oldest('id')
            ->get();

        foreach ($batches as $batch) {
            $waiting = $batch->submissions()->where('status', 'paused')->where('error_message', 'like', 'Waiting:%')->orderBy('id')->get();
            $queued = $submissions->dispatchWithinQuota($batch->user, $waiting);
            if ($queued > 0) {
                $batch->update(['status' => 'processing']);
                $this->info("Batch #{$batch->id}: queued {$queued} waiting URL(s).");
            }
        }

        return self::SUCCESS;
    }
}
