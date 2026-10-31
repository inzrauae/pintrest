<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PinResource;
use App\Models\GeneratedPin;
use App\Models\PublishingJob;
use App\Models\ScheduledPin;
use App\Services\PublishingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class QueueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $with = ['images' => fn ($q) => $q->where('is_selected', true), 'schedule', 'account'];

        return response()->json(['data' => [
            'scheduled' => PinResource::collection(
                GeneratedPin::where('user_id', $userId)->where('status', 'scheduled')->with($with)
                    ->get()->sortBy(fn ($p) => $p->schedule?->scheduled_at)->values()
            ),
            'approved' => PinResource::collection(
                GeneratedPin::where('user_id', $userId)->where('status', 'approved')->with($with)->orderBy('approved_at')->get()
            ),
            'publishing' => PinResource::collection(
                GeneratedPin::where('user_id', $userId)->where('status', 'publishing')->with($with)->latest('updated_at')->get()
            ),
            'failed' => PinResource::collection(
                GeneratedPin::where('user_id', $userId)->where('status', 'failed')->with($with)->latest('updated_at')->limit(100)->get()
            ),
            'jobs' => PublishingJob::where('user_id', $userId)->with('pin:id,title')->latest()->limit(50)->get(),
            'paused' => $request->user()->preferences()->automation_paused,
        ]]);
    }

    /** Re-queues a failed Pin for publication. */
    public function retry(Request $request, GeneratedPin $pin, PublishingService $publishing): JsonResponse
    {
        abort_unless($pin->user_id === $request->user()->id, 403);
        if ($pin->status !== 'failed') {
            throw ValidationException::withMessages(['status' => 'Only failed Pins can be retried.']);
        }
        $publishing->queue($pin, 'manual', $request->boolean('allow_duplicate'));

        return response()->json(['message' => 'Retry queued.']);
    }

    public function cancelJob(Request $request, PublishingJob $job): JsonResponse
    {
        abort_unless($job->user_id === $request->user()->id, 403);
        if ($job->status !== 'queued') {
            throw ValidationException::withMessages(['job' => 'Only queued jobs can be cancelled.']);
        }
        $job->update(['status' => 'cancelled', 'finished_at' => now()]);
        $job->pin?->update(['status' => 'approved', 'error_message' => 'Publishing cancelled by user.']);

        return response()->json(['message' => 'Publishing cancelled.']);
    }

    /** Pause / resume all automation (scheduled + auto-publish). */
    public function toggleAutomation(Request $request): JsonResponse
    {
        $data = $request->validate(['paused' => ['required', 'boolean']]);
        $request->user()->preferences()->update(['automation_paused' => $data['paused']]);

        return response()->json(['paused' => $data['paused']]);
    }

    public function calendar(Request $request): JsonResponse
    {
        $items = ScheduledPin::where('user_id', $request->user()->id)
            ->where('status', 'scheduled')
            ->with('pin:id,title,status')
            ->orderBy('scheduled_at')
            ->get(['id', 'generated_pin_id', 'scheduled_at', 'status']);

        return response()->json(['data' => $items]);
    }
}
