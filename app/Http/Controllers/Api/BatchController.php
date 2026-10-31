<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubmissionResource;
use App\Jobs\ProcessUrlSubmission;
use App\Models\UrlBatch;
use App\Services\UsageLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $batches = $request->user()->urlBatches()->latest()->paginate(20);
        $batches->getCollection()->each(fn (UrlBatch $b) => $b->setAttribute('counts', $b->statusCounts()));

        return response()->json($batches);
    }

    public function show(Request $request, UrlBatch $batch): JsonResponse
    {
        Gate::authorize('view', $batch);

        return response()->json([
            'data' => $batch->setAttribute('counts', $batch->statusCounts()),
            'submissions' => SubmissionResource::collection($batch->submissions()->with('pin')->orderBy('id')->get()),
        ]);
    }

    public function pause(Request $request, UrlBatch $batch): JsonResponse
    {
        Gate::authorize('update', $batch);
        if (in_array($batch->status, ['processing', 'queued'], true)) {
            $batch->update(['status' => 'paused']);
        }

        return $this->show($request, $batch);
    }

    public function resume(Request $request, UrlBatch $batch, UsageLimiter $limiter): JsonResponse
    {
        Gate::authorize('update', $batch);
        if ($batch->status === 'cancelled') {
            return response()->json(['message' => 'A cancelled batch cannot be resumed.'], 422);
        }

        $batch->update(['status' => 'processing']);
        $this->dispatchWithinQuota($batch, $batch->submissions()->whereIn('status', ['paused', 'pending'])->get(), $limiter);
        $batch->refreshCompletion();

        return $this->show($request, $batch);
    }

    public function cancel(Request $request, UrlBatch $batch): JsonResponse
    {
        Gate::authorize('update', $batch);
        $batch->update(['status' => 'cancelled']);
        $batch->submissions()->whereIn('status', ['pending', 'paused'])->update(['status' => 'cancelled']);

        return $this->show($request, $batch);
    }

    /** Retries failed (and optionally cancelled) URLs. */
    public function retry(Request $request, UrlBatch $batch, UsageLimiter $limiter): JsonResponse
    {
        Gate::authorize('update', $batch);
        $statuses = $request->boolean('include_cancelled') ? ['failed', 'cancelled'] : ['failed'];
        $ids = $request->input('submission_ids');

        $submissions = $batch->submissions()
            ->whereIn('status', $statuses)
            ->when(is_array($ids), fn ($q) => $q->whereIn('id', $ids))
            ->get();

        $batch->update(['status' => 'processing']);
        $this->dispatchWithinQuota($batch, $submissions, $limiter);
        $batch->refreshCompletion();

        return $this->show($request, $batch);
    }

    public function export(Request $request, UrlBatch $batch): StreamedResponse
    {
        Gate::authorize('view', $batch);
        $submissions = $batch->submissions()->with(['pin.keywords', 'pin.account'])->orderBy('id')->get();

        return response()->streamDownload(function () use ($submissions) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['url', 'status', 'error', 'pin_id', 'pin_status', 'title', 'description', 'keywords', 'hashtags', 'destination_url', 'board_id', 'pinterest_pin_id', 'published_at']);
            foreach ($submissions as $s) {
                $pin = $s->pin;
                fputcsv($out, array_map([self::class, 'csvSafe'], [
                    $s->url, $s->status, $s->error_message, $pin?->id, $pin?->status, $pin?->title, $pin?->description,
                    $pin ? implode(', ', $pin->keywordList()) : '', $pin ? implode(' ', $pin->keywordList('hashtag')) : '',
                    $pin?->destination_url, $pin?->board_id, $pin?->pinterest_pin_id, $pin?->published_at?->toIso8601String(),
                ]));
            }
            fclose($out);
        }, "batch-{$batch->id}.csv", ['Content-Type' => 'text/csv']);
    }

    /** Neutralises spreadsheet formula injection in exported cells. */
    public static function csvSafe(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private function dispatchWithinQuota(UrlBatch $batch, $submissions, UsageLimiter $limiter): void
    {
        $quota = $limiter->remainingProcessing($batch->user);
        foreach ($submissions as $submission) {
            if ($quota-- <= 0) {
                $submission->update(['status' => 'paused', 'error_message' => 'Waiting: processing limit reached. Resume the batch later.']);

                continue;
            }
            $submission->update(['status' => 'pending', 'error_message' => null]);
            ProcessUrlSubmission::dispatch($submission->id);
        }
    }
}
