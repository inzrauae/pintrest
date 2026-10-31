<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TaskStatusService;
use App\Services\UrlSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard "Running tasks": live status of everything in progress, and a master pause.
 */
class TaskController extends Controller
{
    public function __construct(private readonly TaskStatusService $tasks) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->tasks->forUser($request->user())]);
    }

    /**
     * Pause everything (page batches, publishing, scheduled Pins and the SEO autopilot) or resume it.
     */
    public function pauseAll(Request $request, UrlSubmissionService $submissions): JsonResponse
    {
        $paused = $request->validate(['paused' => ['required', 'boolean']])['paused'];
        $user = $request->user();
        $user->preferences()->update(['automation_paused' => $paused]);

        if ($paused) {
            $user->urlBatches()->whereIn('status', ['processing', 'queued'])->update(['status' => 'paused']);
        } else {
            foreach ($user->urlBatches()->where('status', 'paused')->get() as $batch) {
                $batch->update(['status' => 'processing']);
                $submissions->dispatchWithinQuota($user, $batch->submissions()->whereIn('status', ['paused', 'pending'])->orderBy('id')->get());
                $batch->refreshCompletion();
            }
        }

        return response()->json([
            'message' => $paused ? 'Everything is paused. Running steps finish, nothing new starts.' : 'Resumed.',
            'data' => $this->tasks->forUser($user),
        ]);
    }
}
