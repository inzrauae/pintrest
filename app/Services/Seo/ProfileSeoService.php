<?php

namespace App\Services\Seo;

use App\Models\ExtractedPageData;
use App\Models\GeneratedPin;
use App\Models\PinterestAccount;
use App\Models\PinterestBoard;
use App\Models\UrlSubmission;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\Ai\LlmProviderFactory;
use App\Services\PinGenerationService;
use App\Services\Pinterest\PinterestApiException;
use App\Services\Pinterest\PinterestClient;
use App\Services\Pinterest\PinterestRateLimiter;
use App\Support\UrlNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Pinterest profile SEO: reads the profile (GET /v5/user_account), audits it against the user's keywords,
 * suggests keyword-rich profile text, and creates "profile Pins" — Pins about a search keyword whose link is
 * the user's Pinterest profile, so people searching that keyword land on (and follow) the profile.
 *
 * Pinterest's API cannot edit a profile, so suggested text is for the user to paste into Pinterest.
 */
class ProfileSeoService
{
    public function __construct(
        private readonly PinterestClient $client,
        private readonly KeywordResearchService $research,
        private readonly LlmProviderFactory $llm,
        private readonly PinGenerationService $generation,
    ) {}

    /** The account whose profile is optimised: the default account when it is production, else any active production one. */
    public function account(User $user): ?PinterestAccount
    {
        $accounts = $user->pinterestAccounts()->where('status', 'active')->get();
        $default = $accounts->firstWhere('id', $user->preferences()->default_pinterest_account_id);

        return ($default?->environment === 'production' ? $default : null)
            ?? $accounts->firstWhere('environment', 'production')
            ?? $default
            ?? $accounts->first();
    }

    public static function profileUrl(string $username): string
    {
        return 'https://www.pinterest.com/'.rawurlencode($username).'/';
    }

    /** @return array<string,mixed>|null profile fields, cached for an hour */
    public function profile(User $user, bool $fresh = false): ?array
    {
        $account = $this->account($user);
        if (! $account) {
            return null;
        }

        $key = "pinterest_profile:{$account->id}";
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addHour(), function () use ($account) {
            try {
                $data = $this->client->get($account, 'user_account', [], PinterestRateLimiter::PRIORITY_LOW);
            } catch (PinterestApiException) {
                $data = [];
            }
            $username = $data['username'] ?? $account->username;

            return [
                'account_id' => $account->id,
                'environment' => $account->environment,
                'username' => $username,
                'business_name' => $data['business_name'] ?? null,
                'about' => $data['about'] ?? null,
                'website_url' => $data['website_url'] ?? null,
                'account_type' => $data['account_type'] ?? $account->account_type,
                'profile_image' => $data['profile_image'] ?? $account->profile_image,
                'follower_count' => $data['follower_count'] ?? null,
                'following_count' => $data['following_count'] ?? null,
                'monthly_views' => $data['monthly_views'] ?? null,
                'pin_count' => $data['pin_count'] ?? null,
                'board_count' => $data['board_count'] ?? null,
                'profile_url' => self::profileUrl($username),
                'live' => $data !== [],
            ];
        });
    }

    /**
     * Profile SEO checklist against the user's main keywords.
     *
     * @param  list<string>  $keywords
     * @return array{score:int, checks:list<array{key:string,label:string,passed:bool,tip:string}>}
     */
    public function audit(array $profile, array $keywords, User $user): array
    {
        $tokens = collect($keywords)->flatMap(fn ($k) => $this->research->tokens($k))->unique()->values()->all();
        $has = fn (?string $text) => $text && array_intersect($this->research->tokens($text), $tokens) !== [];
        $aboutHits = collect($keywords)->filter(fn ($k) => $profile['about'] && array_diff($this->research->tokens($k), $this->research->tokens($profile['about'])) === [])->count();

        $boards = PinterestBoard::where('pinterest_account_id', $profile['account_id'])->pluck('name');
        $keywordBoards = $boards->filter(fn ($name) => $has($name))->count();
        $profilePins = GeneratedPin::where('user_id', $user->id)->where('destination_url', $profile['profile_url'])->where('status', 'published')->count();

        $checks = [
            ['key' => 'business', 'label' => 'Business account', 'passed' => strtoupper((string) $profile['account_type']) === 'BUSINESS',
                'tip' => 'Switch to a free Pinterest business account to get analytics and a more trusted profile.'],
            ['key' => 'name', 'label' => 'Display name includes a main keyword', 'passed' => $has($profile['business_name']),
                'tip' => 'Use “Brand | main keyword” as your display name, e.g. “PinBot | POS System Ideas”.'],
            ['key' => 'about', 'label' => 'About text uses 2+ keywords naturally', 'passed' => $aboutHits >= 2,
                'tip' => 'Write 2–3 sentences in your About section that say what you share, using your main search phrases.'],
            ['key' => 'website', 'label' => 'Website claimed', 'passed' => filled($profile['website_url']),
                'tip' => 'Claim your website in Pinterest settings so your Pins get attribution and rank better.'],
            ['key' => 'boards', 'label' => 'Most boards are named after keywords', 'passed' => $boards->isNotEmpty() && $keywordBoards / $boards->count() >= 0.5,
                'tip' => $boards->isEmpty() ? 'Create boards named after what people search.' : "{$keywordBoards} of {$boards->count()} boards use your keywords. Rename or add keyword boards."],
            ['key' => 'profile_pins', 'label' => '3+ profile Pins published', 'passed' => $profilePins >= 3,
                'tip' => 'Create profile Pins for trending keywords below; each one brings searchers to your profile.'],
        ];

        return ['score' => (int) round(collect($checks)->where('passed', true)->count() / count($checks) * 100), 'checks' => $checks];
    }

    /**
     * Keyword-rich display name and About text to paste into Pinterest.
     *
     * @param  list<string>  $keywords
     * @return array{display_name:string, about:string}
     */
    public function suggestText(User $user, array $profile, array $keywords): array
    {
        $keywords = array_values(array_slice(array_unique(array_filter($keywords)), 0, 8));
        $brand = $profile['business_name'] ?: Str::headline((string) $profile['username']);
        $fallback = [
            'display_name' => Str::limit($brand.($keywords ? ' | '.Str::title($keywords[0]) : ''), 65, ''),
            'about' => Str::limit(trim(($profile['about'] ? $profile['about'].' ' : '')
                .($keywords ? 'Ideas and guides on '.implode(', ', array_slice($keywords, 0, 4)).'. Follow for new Pins every week.' : '')), 500, ''),
        ];

        $settings = $user->aiSettingsOrDefault();
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['display_name', 'about'], 'properties' => [
            'display_name' => ['type' => 'string', 'description' => 'Pinterest display name, max 65 characters: brand plus the main keyword, e.g. "Brand | Main Keyword".'],
            'about' => ['type' => 'string', 'description' => 'Pinterest About text, 200-500 characters, natural sentences using 2-4 of the keywords, ending with a reason to follow.'],
        ]];
        $prompt = json_encode(['brand' => $brand, 'current_about' => $profile['about'], 'website' => $profile['website_url'], 'keywords' => $keywords], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $raw = $this->llm->generateJsonWithFallback(
                'You optimise Pinterest business profiles for search. Use only facts given; never invent claims. Respond with JSON matching the schema.',
                "PROFILE AND TARGET KEYWORDS:\n{$prompt}",
                $schema,
                [],
                $settings,
            );
        } catch (AiException) {
            return $fallback;
        }

        $name = trim(strip_tags((string) ($raw['display_name'] ?? '')));
        $about = trim(strip_tags((string) ($raw['about'] ?? '')));

        return [
            'display_name' => $name !== '' ? Str::limit($name, 65, '') : $fallback['display_name'],
            'about' => mb_strlen($about) >= 50 ? Str::limit($about, 500, '') : $fallback['about'],
        ];
    }

    /**
     * Creates a draft Pin about $keyword that links to the user's Pinterest profile, with an AI image.
     */
    public function createProfilePin(User $user, string $keyword): GeneratedPin
    {
        $submission = $this->prepareProfilePin($user, $keyword);

        return $this->finishProfilePin($submission, $keyword);
    }

    /**
     * Fast part (runs in the web request): records the profile as the Pin's source "page".
     * Returns a pending submission; finishProfilePin() (in the queue worker) writes the Pin.
     */
    public function prepareProfilePin(User $user, string $keyword): UrlSubmission
    {
        $keyword = trim(preg_replace('/\s+/u', ' ', strip_tags($keyword)));
        $profile = $this->profile($user);
        if (! $profile) {
            throw new \RuntimeException('Connect a Pinterest account first.');
        }

        $boards = PinterestBoard::where('pinterest_account_id', $profile['account_id'])->orderBy('name')->limit(20)->pluck('name')->all();
        $brand = $profile['business_name'] ?: Str::headline((string) $profile['username']);
        $url = $profile['profile_url'];

        $submission = $user->urlSubmissions()->create([
            'url' => $url,
            'normalized_url' => UrlNormalizer::normalize($url),
            'url_hash' => UrlNormalizer::hash($url),
            'language' => $user->preferences()->language,
            'image_mode' => 'generate',
            'status' => 'pending',
        ]);

        // The "page" is the profile itself: the AI may only use these facts.
        $page = ExtractedPageData::create([
            'url_submission_id' => $submission->id,
            'final_url' => $url,
            'canonical_url' => $url,
            'http_status' => 200,
            'title' => Str::limit("{$keyword} ideas from {$brand} on Pinterest", 480, ''),
            'meta_description' => $profile['about'] ? Str::limit($profile['about'], 1000, '') : null,
            'headings' => [['level' => 'h1', 'text' => Str::title($keyword)]],
            'images' => [],
            'content' => implode("\n", array_filter([
                "This Pin promotes the Pinterest profile of {$brand} (@{$profile['username']}) to people searching for \"{$keyword}\".",
                'Invite them to follow the profile for more ideas about '.$keyword.'. Do not claim anything not stated here.',
                $profile['about'] ? 'About the profile: '.$profile['about'] : null,
                $profile['website_url'] ? 'Website: '.$profile['website_url'] : null,
                $boards ? 'Boards on the profile: '.implode(', ', $boards).'.' : null,
            ])),
            'page_language' => $user->preferences()->language,
        ]);

        return $submission;
    }

    /** Slow part: AI copy, keyword research and image, then profile-specific account/board. */
    public function finishProfilePin(UrlSubmission $submission, string $keyword): GeneratedPin
    {
        $user = $submission->user;
        $profile = $this->profile($user);
        if (! $profile) {
            $submission->update(['status' => 'failed', 'error_message' => 'The Pinterest account was disconnected.']);
            throw new \RuntimeException('Connect a Pinterest account first.');
        }
        $url = $submission->url;
        $submission->update(['status' => 'processing', 'error_message' => null, 'attempts' => $submission->attempts + 1]);

        try {
            $pin = $this->generation->processPage($submission, $submission->extracted);
        } catch (\Throwable $e) {
            $submission->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        }

        // Publish from the account whose profile it links to — except on Trial access, where Pinterest only lets
        // apps create Pins in the sandbox, so a sandbox account publishes it (the link still goes to the real profile).
        $publishAccountId = $profile['account_id'];
        if ($profile['environment'] === 'production' && app(PinterestRateLimiter::class)->level() === 'trial') {
            $publishAccountId = $user->pinterestAccounts()->where('environment', 'sandbox')->where('status', 'active')->value('id') ?? $publishAccountId;
        }

        // On that account, use the board that best matches the keyword.
        $accountBoards = PinterestBoard::where('pinterest_account_id', $publishAccountId)->get(['board_id', 'name']);
        $keywordTokens = $this->research->tokens($keyword);
        $best = $accountBoards->sortByDesc(fn ($b) => count(array_intersect($this->research->tokens($b->name), $keywordTokens)))->first();
        $boardId = $best && array_intersect($this->research->tokens($best->name), $keywordTokens)
            ? $best->board_id
            : ($accountBoards->contains('board_id', $pin->board_id) ? $pin->board_id : null);

        $pin->update([
            'destination_url' => $url,
            'pinterest_account_id' => $publishAccountId,
            'board_id' => $boardId,
            'ai_metadata' => array_merge($pin->ai_metadata ?? [], ['kind' => 'profile', 'profile_keyword' => $keyword]),
        ]);

        return $pin->fresh(['images', 'keywords']);
    }
}
