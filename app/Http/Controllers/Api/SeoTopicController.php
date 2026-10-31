<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeneratedPin;
use App\Models\PinterestBoard;
use App\Models\SeoTopic;
use App\Services\Seo\KeywordResearchService;
use App\Services\Seo\PinAnalyticsService;
use App\Services\Seo\TopicAutopilot;
use App\Services\UsageLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * SEO topics: the autopilot (discover → AI Pins → publish) and per-topic Pinterest analytics.
 */
class SeoTopicController extends Controller
{
    public function __construct(
        private readonly PinAnalyticsService $analytics,
        private readonly KeywordResearchService $research,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $topics = $user->seoTopics()->latest()->get();
        $prefs = $user->preferences();

        return response()->json([
            'data' => $topics->map(fn (SeoTopic $t) => $this->present($t))->all(),
            'analytics' => $this->analytics->summary($user->pins()->pluck('id')),
            'automation' => [
                'auto_publish' => (bool) $prefs->auto_publish && (bool) $prefs->auto_publish_acknowledged_at,
                'paused' => (bool) $prefs->automation_paused,
                'daily_publish_limit' => app(UsageLimiter::class)->dailyPublishLimit($user),
                'spacing_minutes' => $prefs->auto_publish_spacing_minutes,
                'production_connected' => $user->pinterestAccounts()->where('environment', 'production')->where('status', 'active')->exists(),
            ],
        ]);
    }

    public function show(Request $request, SeoTopic $topic): JsonResponse
    {
        Gate::authorize('view', $topic);
        $pinIds = $topic->pins()->pluck('id');

        $top = GeneratedPin::whereIn('generated_pins.id', $pinIds)
            ->leftJoin('pin_analytics', 'pin_analytics.generated_pin_id', '=', 'generated_pins.id')
            ->groupBy('generated_pins.id', 'generated_pins.title', 'generated_pins.status', 'generated_pins.seo_score', 'generated_pins.pinterest_pin_id')
            ->selectRaw('generated_pins.id, generated_pins.title, generated_pins.status, generated_pins.seo_score, generated_pins.pinterest_pin_id,
                coalesce(sum(pin_analytics.impressions),0) impressions, coalesce(sum(pin_analytics.saves),0) saves,
                coalesce(sum(pin_analytics.outbound_clicks),0) outbound_clicks')
            ->orderByDesc('impressions')->orderByDesc('generated_pins.id')
            ->limit(20)->get();

        return response()->json([
            'data' => $this->present($topic),
            'pins' => $top,
            // Real Pinterest searches around the topic, with growth when trending (cached by the research service).
            'keywords' => $this->research->research($request->user(), $topic->keywords, implode(' ', $topic->keywords).' '.$topic->name),
            'trends' => $this->research->trending($request->user(), null, 'growing', null, $topic->keywords),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $topic = new SeoTopic(['user_id' => $request->user()->id]);
        $topic->fill($this->validated($request))->save();

        return response()->json(['data' => $this->present($topic->fresh())], 201);
    }

    public function update(Request $request, SeoTopic $topic): JsonResponse
    {
        Gate::authorize('update', $topic);
        $topic->update($this->validated($request, $topic));

        return response()->json(['data' => $this->present($topic->fresh())]);
    }

    public function destroy(SeoTopic $topic): JsonResponse
    {
        Gate::authorize('delete', $topic);
        $topic->delete(); // its Pins stay; seo_topic_id is nulled

        return response()->json(['message' => 'Topic deleted. Its Pins were kept.']);
    }

    /** Discover now instead of waiting for the hourly autopilot. */
    public function run(SeoTopic $topic, TopicAutopilot $autopilot): JsonResponse
    {
        Gate::authorize('update', $topic);
        @set_time_limit(300);
        $summary = $autopilot->run($topic->load('user'));

        return response()->json(['data' => $this->present($topic->fresh()), 'run' => $summary], $summary['error'] ? 422 : 200);
    }

    public function syncAnalytics(Request $request): JsonResponse
    {
        @set_time_limit(300);
        $count = $this->analytics->syncDue(50, 0, $request->user()->id);

        return response()->json(['message' => $count
            ? "Updated analytics for {$count} published Pin(s)."
            : 'No production Pins to update yet. Analytics are available for Pins published with a production (not sandbox) account.']);
    }

    private function validated(Request $request, ?SeoTopic $topic = null): array
    {
        $userId = $request->user()->id;
        $data = $request->validate([
            'name' => [$topic ? 'sometimes' : 'required', 'string', 'max:100'],
            'keywords' => [$topic ? 'sometimes' : 'required', 'array', 'min:1', 'max:20'],
            'keywords.*' => ['nullable', 'string', 'max:80'],
            'source_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'regex:#^https?://#i'],
            'url_filters' => ['sometimes', 'nullable', 'array', 'max:20'],
            'url_filters.*' => ['string', 'max:100'],
            'pinterest_account_id' => ['sometimes', 'nullable', Rule::exists('pinterest_accounts', 'id')->where('user_id', $userId)],
            'board_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'pin_template_id' => ['sometimes', 'nullable', Rule::exists('pin_templates', 'id')->where('user_id', $userId)],
            'language' => ['sometimes', Rule::in(array_keys(config('pinbot.languages')))],
            'image_mode' => ['sometimes', Rule::in(['extract', 'generate', 'both'])],
            'owns_images' => ['sometimes', 'boolean'],
            'auto_discover' => ['sometimes', 'boolean'],
            'auto_approve' => ['sometimes', 'boolean'],
            'max_new_per_run' => ['sometimes', 'integer', 'between:1,100'],
            'frequency_hours' => ['sometimes', 'integer', 'between:1,720'],
        ]);

        foreach (['keywords', 'url_filters'] as $list) {
            if (array_key_exists($list, $data)) {
                $data[$list] = array_values(array_unique(array_filter(array_map(fn ($k) => trim(strip_tags((string) $k)), $data[$list] ?? []))));
            }
        }
        if (array_key_exists('keywords', $data) && $data['keywords'] === []) {
            throw ValidationException::withMessages(['keywords' => 'Add at least one keyword.']);
        }

        $accountId = $data['pinterest_account_id'] ?? $topic?->pinterest_account_id;
        $boardId = $data['board_id'] ?? $topic?->board_id;
        if ($boardId && (! $accountId || ! PinterestBoard::where('pinterest_account_id', $accountId)->where('board_id', $boardId)->exists())) {
            throw ValidationException::withMessages(['board_id' => 'Choose a board from the selected Pinterest account.']);
        }

        return $data;
    }

    private function present(SeoTopic $topic): array
    {
        $pins = $topic->pins();
        $counts = (clone $pins)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')->map(fn ($c) => (int) $c);
        $board = $topic->board_id && $topic->pinterest_account_id
            ? PinterestBoard::where('pinterest_account_id', $topic->pinterest_account_id)->where('board_id', $topic->board_id)->value('name')
            : null;

        return $topic->only([
            'id', 'name', 'keywords', 'source_url', 'url_filters', 'pinterest_account_id', 'board_id', 'pin_template_id', 'language',
            'image_mode', 'owns_images', 'auto_discover', 'auto_approve', 'max_new_per_run', 'frequency_hours', 'last_run_at', 'last_run',
        ]) + [
            'board_name' => $board,
            'next_run_at' => $topic->auto_discover && $topic->source_url ? ($topic->last_run_at?->copy()->addHours($topic->frequency_hours) ?? now()) : null,
            'pins' => [
                'total' => $counts->sum(),
                'by_status' => $counts,
                'average_seo' => ($avg = (clone $pins)->avg('seo_score')) !== null ? (int) round($avg) : null,
            ],
            'analytics' => $this->analytics->summary((clone $pins)->pluck('id')),
        ];
    }
}
