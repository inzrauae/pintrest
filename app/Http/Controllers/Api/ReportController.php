<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeneratedPin;
use App\Models\PublicationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $days = min(max((int) $request->query('days', 30), 7), 365);
        $user = $request->user();
        $since = now()->subDays($days - 1)->startOfDay();

        $series = fn ($query, string $column) => $query
            ->where($column, '>=', $since)
            ->get([$column])
            ->groupBy(fn ($row) => $row->{$column}->toDateString())
            ->map->count();

        $generated = $series(GeneratedPin::where('user_id', $user->id), 'created_at');
        $published = $series(GeneratedPin::where('user_id', $user->id)->whereNotNull('published_at'), 'published_at');
        $submitted = $series($user->urlSubmissions(), 'created_at');

        $daily = [];
        for ($d = $since->copy(); $d->lte(now()); $d->addDay()) {
            $key = $d->toDateString();
            $daily[] = [
                'date' => $key,
                'submitted' => $submitted[$key] ?? 0,
                'generated' => $generated[$key] ?? 0,
                'published' => $published[$key] ?? 0,
            ];
        }

        $byStatus = GeneratedPin::where('user_id', $user->id)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $submissionStatus = $user->urlSubmissions()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        $topErrors = PublicationLog::where('user_id', $user->id)
            ->where('level', 'error')
            ->where('created_at', '>=', $since)
            ->selectRaw('event, message, count(*) as c')
            ->groupBy('event', 'message')
            ->orderByDesc('c')
            ->limit(10)
            ->get();

        return response()->json(['data' => [
            'days' => $days,
            'daily' => $daily,
            'pins_by_status' => $byStatus,
            'submissions_by_status' => $submissionStatus,
            'top_errors' => $topErrors,
        ]]);
    }

    /** Developer-friendly log including raw Pinterest/AI error details (the user's own records only). */
    public function logs(Request $request): JsonResponse
    {
        $logs = PublicationLog::where('user_id', $request->user()->id)
            ->when($request->query('level'), fn ($q, $level) => $q->where('level', $level))
            ->when($request->query('pin_id'), fn ($q, $id) => $q->where('generated_pin_id', $id))
            ->latest('created_at')->latest('id')
            ->paginate(50);

        return response()->json($logs);
    }

    /** Admin: failed queue jobs across the system. */
    public function failedJobs(Request $request): JsonResponse
    {
        abort_unless($request->user()->is_admin, 403);

        $jobs = DB::table('failed_jobs')->latest('failed_at')->limit(100)->get()->map(fn ($job) => [
            'id' => $job->id,
            'uuid' => $job->uuid,
            'queue' => $job->queue,
            'job' => json_decode($job->payload, true)['displayName'] ?? 'unknown',
            'exception' => mb_substr($job->exception, 0, 2000),
            'failed_at' => $job->failed_at,
        ]);

        return response()->json([
            'data' => $jobs,
            'recent_errors' => PublicationLog::where('level', 'error')->latest('created_at')->limit(50)->get(),
        ]);
    }

    public function retryFailedJob(Request $request, string $uuid): JsonResponse
    {
        abort_unless($request->user()->is_admin, 403);
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return response()->json(['message' => 'Job pushed back onto the queue.']);
    }

    public function forgetFailedJob(Request $request, string $uuid): JsonResponse
    {
        abort_unless($request->user()->is_admin, 403);
        Artisan::call('queue:forget', ['id' => $uuid]);

        return response()->json(['message' => 'Failed job deleted.']);
    }
}
