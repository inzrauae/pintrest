<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeneratedPin;
use App\Models\PublicationLog;
use App\Services\UsageLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, UsageLimiter $limiter): JsonResponse
    {
        $user = $request->user();
        $pins = GeneratedPin::where('user_id', $user->id);
        $byStatus = (clone $pins)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $prefs = $user->preferences();

        return response()->json(['data' => [
            'stats' => [
                'urls_submitted' => $user->urlSubmissions()->count(),
                'pins_generated' => (int) $byStatus->sum(),
                'pins_published' => (int) ($byStatus['published'] ?? 0),
                'pending_approval' => (int) ($byStatus['draft'] ?? 0),
                'approved' => (int) ($byStatus['approved'] ?? 0),
                'scheduled' => (int) ($byStatus['scheduled'] ?? 0),
                'publishing' => (int) ($byStatus['publishing'] ?? 0),
                'failed' => (int) ($byStatus['failed'] ?? 0),
                'processing' => $user->urlSubmissions()->whereIn('status', ['pending', 'processing'])->count(),
            ],
            'limits' => [
                'published_today' => $limiter->publishedToday($user),
                'daily_publish_limit' => $prefs->daily_publish_limit,
                'remaining_processing' => $limiter->remainingProcessing($user),
                'automation_paused' => $prefs->automation_paused,
                'auto_publish' => $prefs->auto_publish,
            ],
            'accounts_connected' => $user->pinterestAccounts()->where('status', 'active')->count(),
            'recent_activity' => PublicationLog::where('user_id', $user->id)
                ->latest('created_at')->latest('id')->limit(15)
                ->get(['id', 'level', 'event', 'message', 'generated_pin_id', 'created_at']),
        ]]);
    }
}
