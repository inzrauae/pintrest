<?php

namespace App\Services;

use App\Models\GeneratedPin;
use App\Models\PublicationLog;
use App\Models\ScheduledPin;
use App\Models\SeoTopic;
use App\Models\UrlBatch;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Everything that is running or waiting for a user, for the dashboard's "Running tasks" panel:
 * background services, page batches, Pin generation, publishing and SEO autopilot topics.
 */
class TaskStatusService
{
    public const WORKER_HEARTBEAT = 'pinbot:worker_heartbeat';

    public const SCHEDULER_HEARTBEAT = 'pinbot:scheduler_heartbeat';

    public function __construct(private readonly UsageLimiter $limiter) {}

    public function forUser(User $user): array
    {
        $prefs = $user->preferences();

        $batches = $user->urlBatches()
            ->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereIn('status', ['processing', 'queued', 'paused'])
                ->orWhereHas('submissions', fn ($s) => $s->whereIn('status', ['pending', 'processing', 'paused'])))
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (UrlBatch $b) => $this->batch($b));

        $submissions = $user->urlSubmissions();
        $processingNow = (clone $submissions)->where('status', 'processing')->latest('updated_at')->limit(5)->pluck('url');

        $publishingNow = GeneratedPin::where('user_id', $user->id)->where('status', 'publishing')
            ->latest('updated_at')->limit(5)->get(['id', 'title', 'error_message', 'updated_at']);
        $nextScheduled = ScheduledPin::where('user_id', $user->id)->where('status', 'scheduled')->orderBy('scheduled_at')->first(['scheduled_at']);

        $topics = $user->seoTopics()->orderBy('name')->get()->map(fn (SeoTopic $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'active' => $t->auto_discover && (bool) $t->source_url,
            'paused' => ! $t->auto_discover,
            'last_run_at' => $t->last_run_at,
            'next_run_at' => $t->auto_discover && $t->source_url ? ($t->last_run_at?->copy()->addHours($t->frequency_hours) ?? now()) : null,
            'last_run' => $t->last_run,
        ]);

        $pending = (clone $submissions)->where('status', 'pending')->count();
        $processing = (clone $submissions)->where('status', 'processing')->count();
        $waiting = (clone $submissions)->where('status', 'paused')->where('error_message', 'like', 'Waiting:%')->count();
        $publishing = GeneratedPin::where('user_id', $user->id)->where('status', 'publishing')->count();

        return [
            'services' => $this->services(),
            'paused' => (bool) $prefs->automation_paused,
            'active' => $processing + $pending + $publishing > 0 || $batches->contains(fn ($b) => $b['status'] === 'processing'),
            'batches' => $batches->values(),
            'generation' => [
                'processing' => $processing,
                'pending' => $pending,
                'waiting_for_quota' => $waiting,
                'remaining_quota' => $this->limiter->remainingProcessing($user),
                'limits' => ['hourly' => $prefs->hourly_processing_limit, 'daily' => $prefs->daily_processing_limit],
                'now' => $processingNow,
                // Pages becoming Pins right now / next, for "in progress" cards in the Pin Library.
                'items' => (clone $submissions)->whereIn('status', ['processing', 'pending'])
                    ->orderByRaw("case when status = 'processing' then 0 else 1 end")->orderBy('id')
                    ->limit(12)->get(['id', 'url', 'status', 'url_batch_id', 'created_at', 'updated_at']),
            ],
            'publishing' => [
                'paused' => (bool) $prefs->automation_paused,
                'auto_publish' => (bool) $prefs->auto_publish && (bool) $prefs->auto_publish_acknowledged_at,
                'publishing' => $publishing,
                'now' => $publishingNow,
                'approved' => GeneratedPin::where('user_id', $user->id)->where('status', 'approved')->count(),
                'scheduled' => ScheduledPin::where('user_id', $user->id)->where('status', 'scheduled')->count(),
                'next_scheduled_at' => $nextScheduled?->scheduled_at,
                'published_today' => $this->limiter->publishedToday($user),
                'daily_limit' => $this->limiter->dailyPublishLimit($user),
            ],
            'topics' => $topics->values(),
            // What finished recently, so an idle panel still shows that work happened.
            'today' => [
                'generated' => GeneratedPin::where('user_id', $user->id)->where('created_at', '>=', $prefs->startOfLocalDay())->count(),
                'published' => $this->limiter->publishedToday($user),
                'failed' => PublicationLog::where('user_id', $user->id)->where('level', 'error')->where('created_at', '>=', $prefs->startOfLocalDay())->count(),
            ],
            'recent' => PublicationLog::where('user_id', $user->id)
                ->whereIn('event', ['pin_generated', 'pin_published', 'publish_failed', 'publish_error', 'url_failed', 'topic_autopilot', 'image_generation_failed', 'pin_auto_approved'])
                ->latest('id')->limit(6)
                ->get(['id', 'event', 'level', 'message', 'generated_pin_id', 'created_at']),
        ];
    }

    /** Whether the queue worker and the scheduler are alive, plus the queue backlog. */
    public function services(): array
    {
        $worker = (int) Cache::get(self::WORKER_HEARTBEAT, 0);
        $scheduler = (int) Cache::get(self::SCHEDULER_HEARTBEAT, 0);
        $backlog = config('queue.default') === 'database'
            ? DB::table(config('queue.connections.database.table', 'jobs'))->selectRaw('queue, count(*) c')->groupBy('queue')->pluck('c', 'queue')->map(fn ($c) => (int) $c)
            : collect();

        return [
            'worker' => ['running' => $worker > time() - 120, 'last_seen_at' => $worker ? date(DATE_ATOM, $worker) : null],
            'scheduler' => ['running' => $scheduler > time() - 150, 'last_seen_at' => $scheduler ? date(DATE_ATOM, $scheduler) : null],
            'queue' => $backlog,
        ];
    }

    private function batch(UrlBatch $batch): array
    {
        $counts = $batch->statusCounts();
        $done = ($counts['generated'] ?? 0) + ($counts['failed'] ?? 0) + ($counts['duplicate'] ?? 0) + ($counts['cancelled'] ?? 0);
        $waiting = $batch->submissions()->where('status', 'paused')->where('error_message', 'like', 'Waiting:%')->count();

        return [
            'id' => $batch->id,
            'name' => $batch->name,
            // A batch whose URLs only wait for quota is "waiting", not paused by the user.
            'status' => $batch->status === 'completed' && $waiting ? 'waiting' : $batch->status,
            'total' => $batch->total,
            'done' => $done,
            'percent' => $batch->total ? (int) floor($done / $batch->total * 100) : 0,
            'counts' => $counts,
            'waiting_for_quota' => $waiting,
            'topic' => $batch->options['topic_id'] ?? null,
            'created_at' => $batch->created_at,
            'updated_at' => $batch->updated_at,
        ];
    }
}
