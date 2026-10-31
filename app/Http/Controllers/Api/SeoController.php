<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PinResource;
use App\Jobs\CreateProfilePin;
use App\Models\GeneratedPin;
use App\Models\PinKeyword;
use App\Models\PinterestBoard;
use App\Services\Pinterest\PinterestRateLimiter;
use App\Services\PinGenerationService;
use App\Services\Seo\KeywordResearchService;
use App\Services\Seo\ProfileSeoService;
use App\Services\Seo\SeoScorer;
use App\Services\UsageLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * SEO dashboard: Pin scores, common issues, keyword coverage, board names, and Pinterest keyword research.
 */
class SeoController extends Controller
{
    public function __construct(
        private readonly KeywordResearchService $research,
        private readonly SeoScorer $scorer,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();

        // Score Pins created before scoring existed (bounded per request).
        $user->pins()->whereNull('seo_score')->with('keywords')->limit(200)->get()->each(fn ($pin) => $this->scorer->refresh($pin));

        $pins = $user->pins()->whereNotNull('seo_score');
        $total = (clone $pins)->count();

        // Most common failed checks across the user's most recent Pins.
        $issues = [];
        foreach ($user->pins()->with('keywords')->latest()->limit(300)->get() as $pin) {
            foreach ($this->scorer->evaluate($pin)['checks'] as $check) {
                if (! $check['passed']) {
                    $issues[$check['key']] ??= ['key' => $check['key'], 'label' => $check['label'], 'count' => 0];
                    $issues[$check['key']]['count']++;
                }
            }
        }
        usort($issues, fn ($a, $b) => $b['count'] <=> $a['count']);

        $topKeywords = PinKeyword::query()
            ->whereIn('generated_pin_id', $user->pins()->select('id'))
            ->where('type', 'keyword')
            ->selectRaw('lower(keyword) as keyword, count(*) as pins')
            ->groupByRaw('lower(keyword)')
            ->orderByDesc('pins')
            ->limit(20)
            ->get();

        $researched = collect($user->pins()->whereNotNull('researched_keywords')->latest()->limit(300)->pluck('researched_keywords'))
            ->flatten(1)
            ->filter(fn ($r) => is_array($r) && isset($r['keyword']))
            ->groupBy('keyword')
            ->map(fn ($rows, $keyword) => ['keyword' => $keyword, 'pins' => $rows->count(), 'growth' => $rows->max('growth')])
            ->sortByDesc('pins')
            ->values()
            ->take(20);

        return response()->json(['data' => [
            'research' => $this->research->status($user),
            'stats' => [
                'pins' => $total,
                'average' => $total ? (int) round((clone $pins)->avg('seo_score')) : null,
                'good' => (clone $pins)->where('seo_score', '>=', 80)->count(),
                'fair' => (clone $pins)->whereBetween('seo_score', [50, 79])->count(),
                'poor' => (clone $pins)->where('seo_score', '<', 50)->count(),
                'with_research' => $user->pins()->whereNotNull('researched_keywords')->where('researched_keywords', '!=', '[]')->count(),
            ],
            'issues' => array_slice($issues, 0, 8),
            'top_keywords' => $topKeywords,
            'researched_keywords' => $researched,
            'boards' => $this->boards($request, $topKeywords->pluck('keyword')->merge($researched->pluck('keyword'))->all()),
            // Pinterest API budget used in the current window, per environment (sandbox and production are counted apart).
            // The user's own PinBot limits (User Settings), separate from Pinterest's API limits.
            'own_limits' => [
                'published_today' => app(UsageLimiter::class)->publishedToday($user),
                'daily_publish_limit' => app(UsageLimiter::class)->dailyPublishLimit($user),
                'max_daily_publish_limit' => app(PinterestRateLimiter::class)->maxDailyPublishes(),
                'processed_today' => app(UsageLimiter::class)->processedToday($user),
                'daily_processing_limit' => (int) $user->preferences()->daily_processing_limit,
                'processed_last_hour' => app(UsageLimiter::class)->processedInLastHour($user),
                'hourly_processing_limit' => (int) $user->preferences()->hourly_processing_limit,
            ],
            'api_usage' => $user->pinterestAccounts()->where('status', 'active')->get()->unique('environment')
                ->map(fn ($account) => ['environment' => $account->environment] + app(PinterestRateLimiter::class)->usage($account))
                ->values(),
        ]]);
    }

    /** Pins with their score and failed checks, weakest first by default. */
    public function pins(Request $request): JsonResponse
    {
        $data = $request->validate([
            'band' => ['nullable', Rule::in(['good', 'fair', 'poor'])],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['score_asc', 'score_desc', 'newest'])],
        ]);

        $query = $request->user()->pins()->with(['keywords', 'images']);
        match ($data['band'] ?? null) {
            'good' => $query->where('seo_score', '>=', 80),
            'fair' => $query->whereBetween('seo_score', [50, 79]),
            'poor' => $query->where('seo_score', '<', 50),
            default => null,
        };
        if ($search = $data['search'] ?? null) {
            $query->where('title', 'like', '%'.addcslashes($search, '%_').'%');
        }
        match ($data['sort'] ?? 'score_asc') {
            'score_desc' => $query->orderByDesc('seo_score'),
            'newest' => $query->latest(),
            default => $query->orderBy('seo_score'),
        };

        $page = $query->paginate(min((int) $request->query('per_page', 20), 100));
        $page->getCollection()->transform(function (GeneratedPin $pin) {
            $result = $this->scorer->evaluate($pin);

            return (new PinResource($pin))->resolve() + ['seo' => $result];
        });

        return response()->json($page);
    }

    public function keywords(Request $request): JsonResponse
    {
        $data = $request->validate(['term' => ['required', 'string', 'min:2', 'max:80']]);
        $user = $request->user();
        $term = $data['term'];

        return response()->json(['data' => [
            'term' => $term,
            'keywords' => $this->research->research($user, [$term], $term),
            'suggested' => $this->research->suggested($user, $term),
            'related' => $this->research->related($user, [$term]),
            'research' => $this->research->status($user),
        ]]);
    }

    public function trends(Request $request): JsonResponse
    {
        $data = $request->validate([
            'region' => ['nullable', Rule::in(KeywordResearchService::REGIONS)],
            'type' => ['nullable', Rule::in(KeywordResearchService::TREND_TYPES)],
            'interest' => ['nullable', Rule::in(KeywordResearchService::INTERESTS)],
            'keywords' => ['nullable', 'string', 'max:1000'],
        ]);
        $include = array_values(array_filter(array_map('trim', explode(',', (string) ($data['keywords'] ?? '')))));

        return response()->json(['data' => [
            'trends' => $this->research->trending($request->user(), $data['region'] ?? null, $data['type'] ?? 'monthly', $data['interest'] ?? null, $include),
            'research' => $this->research->status($request->user()),
            'options' => ['regions' => KeywordResearchService::REGIONS, 'types' => KeywordResearchService::TREND_TYPES, 'interests' => KeywordResearchService::INTERESTS],
        ]]);
    }

    public function rescore(Request $request): JsonResponse
    {
        $count = 0;
        $request->user()->pins()->with('keywords')->chunkById(200, function ($pins) use (&$count) {
            foreach ($pins as $pin) {
                $this->scorer->refresh($pin);
                $count++;
            }
        });

        return response()->json(['message' => "Recalculated {$count} Pin score(s)."]);
    }

    /** Pinterest profile, its SEO checklist and recent profile Pins. */
    public function profile(Request $request, ProfileSeoService $profiles): JsonResponse
    {
        $user = $request->user();
        $profile = $profiles->profile($user, $request->boolean('refresh'));
        if (! $profile) {
            return response()->json(['data' => null]);
        }
        $keywords = $this->mainKeywords($request);

        return response()->json(['data' => [
            'profile' => $profile,
            'keywords' => $keywords,
            'audit' => $profiles->audit($profile, $keywords, $user),
            'pins' => $user->pins()->where('destination_url', $profile['profile_url'])->latest()->limit(8)
                ->get(['id', 'title', 'status', 'ai_metadata', 'created_at'])
                ->map(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'status' => $p->status, 'keyword' => $p->ai_metadata['profile_keyword'] ?? null, 'created_at' => $p->created_at]),
        ]]);
    }

    public function profileSuggest(Request $request, ProfileSeoService $profiles): JsonResponse
    {
        $profile = $profiles->profile($request->user());
        abort_unless($profile, 422, 'Connect a Pinterest account first.');
        @set_time_limit(120);

        return response()->json(['data' => $profiles->suggestText($request->user(), $profile, $this->mainKeywords($request))]);
    }

    /** Creates a profile Pin: a Pin about a (trending) keyword that links to the user's Pinterest profile. */
    public function profilePin(Request $request, ProfileSeoService $profiles, UsageLimiter $limiter): JsonResponse
    {
        $data = $request->validate(['keyword' => ['required', 'string', 'min:2', 'max:80']]);
        $user = $request->user();
        if ($limiter->remainingProcessing($user) < 1) {
            throw ValidationException::withMessages(['keyword' => 'You have reached your hourly or daily processing limit. Try again later or raise it in User Settings.']);
        }
        try {
            $submission = $profiles->prepareProfilePin($user, $data['keyword']);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['keyword' => $e->getMessage()]);
        }
        // The slow part (AI copy, research, image) runs in the queue worker so this request returns at once.
        CreateProfilePin::dispatch($submission->id, $data['keyword']);

        return response()->json([
            'message' => 'Creating the profile Pin for “'.$data['keyword'].'” in the background. It appears in your Pin Library in a minute or two.',
            'submission_id' => $submission->id,
        ], 202);
    }

    /** @return list<string> the user's main keywords: SEO topic keywords, then researched searches, then Pin keywords */
    private function mainKeywords(Request $request): array
    {
        $user = $request->user();
        $topic = $user->seoTopics()->pluck('keywords')->flatten();
        $researched = collect($user->pins()->whereNotNull('researched_keywords')->latest()->limit(100)->pluck('researched_keywords'))
            ->flatten(1)->pluck('keyword')->countBy()->sortDesc()->keys();
        $own = PinKeyword::whereIn('generated_pin_id', $user->pins()->select('id'))->where('type', 'keyword')
            ->selectRaw('lower(keyword) k, count(*) c')->groupByRaw('lower(keyword)')->orderByDesc('c')->limit(10)->pluck('k');

        return $topic->merge($researched->take(10))->merge($own)->map(fn ($k) => mb_strtolower(trim((string) $k)))->filter()->unique()->take(12)->values()->all();
    }

    /** Rewrites title, description, keywords, alt text and board suggestion around researched Pinterest keywords. */
    public function optimize(Request $request, GeneratedPin $pin, PinGenerationService $service): JsonResponse
    {
        Gate::authorize('update', $pin);
        if (! $pin->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Published Pins cannot be changed here.']);
        }

        @set_time_limit(180);
        // Re-research when earlier research found nothing, so the rewrite has real search terms to use.
        if ($pin->researched_keywords === []) {
            $pin->update(['researched_keywords' => null]);
        }
        $pin = $service->regenerate($pin, ['title', 'description', 'keywords', 'alt_text', 'board']);

        return response()->json([
            'data' => (new PinResource($pin->load(['keywords', 'images'])))->resolve() + ['seo' => $this->scorer->evaluate($pin)],
        ]);
    }

    /**
     * The user's boards with how many of their Pins use them and whether the name carries a keyword,
     * plus keyword-rich names for boards they do not have yet.
     */
    private function boards(Request $request, array $keywords): array
    {
        $user = $request->user();
        $boards = PinterestBoard::whereIn('pinterest_account_id', $user->pinterestAccounts()->pluck('id'))->orderBy('name')->get();
        $usage = $user->pins()->whereNotNull('board_id')->selectRaw('board_id, count(*) as c')->groupBy('board_id')->pluck('c', 'board_id');
        $keywordTokens = collect($keywords)->flatMap(fn ($k) => $this->research->tokens($k))->unique()->all();

        $list = $boards->unique('board_id')->map(fn ($b) => [
            'id' => $b->id,
            'board_id' => $b->board_id,
            'name' => $b->name,
            'pinterest_account_id' => $b->pinterest_account_id,
            'pins' => (int) ($usage[$b->board_id] ?? 0),
            'keyword_rich' => array_intersect($this->research->tokens($b->name), $keywordTokens) !== [],
        ])->values();

        $existing = $boards->map(fn ($b) => $this->research->tokens($b->name))->all();
        $suggestions = collect($keywords)
            ->map(fn ($k) => Str::title($k))
            ->unique()
            ->reject(fn ($name) => collect($existing)->contains(fn ($tokens) => array_diff($this->research->tokens($name), $tokens) === []))
            ->take(8)
            ->values();

        return ['list' => $list, 'suggestions' => $suggestions];
    }
}
