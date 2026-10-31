<?php

namespace App\Services\Seo;

use App\Models\ExtractedPageData;
use App\Models\PinterestAccount;
use App\Models\User;
use App\Services\Pinterest\PinterestApiException;
use App\Services\Pinterest\PinterestClient;
use App\Services\Pinterest\PinterestRateLimiter;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Pinterest keyword research through the official API v5:
 *  - GET /terms/suggested  popular searches starting with a term   (scope ads:read)
 *  - GET /terms/related    searches related to terms               (scope ads:read)
 *  - GET /trends/keywords/{region}/top/{type}  trending searches + growth (scope user_accounts:read)
 *
 * Results are cached; an endpoint the app is not allowed to use is remembered as unavailable for a while,
 * so Pin generation never fails or slows down because research is missing.
 */
class KeywordResearchService
{
    public const REGIONS = ['US', 'CA', 'DE', 'FR', 'ES', 'IT', 'DE+AT+CH', 'GB+IE', 'IT+ES+PT+GR+MT', 'PL+RO+HU+SK+CZ', 'SE+DK+FI+NO', 'NL+BE+LU', 'AR', 'BR', 'CO', 'MX', 'MX+AR+CO+CL', 'AU+NZ'];

    public const TREND_TYPES = ['growing', 'monthly', 'yearly', 'seasonal'];

    public const INTERESTS = ['animals', 'architecture', 'art', 'beauty', 'childrens_fashion', 'design', 'diy_and_crafts', 'education', 'electronics', 'entertainment', 'event_planning', 'finance', 'food_and_drinks', 'gardening', 'health', 'home_decor', 'mens_fashion', 'parenting', 'quotes', 'sport', 'travel', 'vehicles', 'wedding', 'womens_fashion'];

    public const STOPWORDS = ['the', 'and', 'for', 'with', 'that', 'this', 'from', 'your', 'you', 'are', 'how', 'what', 'our', 'was',
        'has', 'have', 'will', 'can', 'all', 'not', 'but', 'its', 'into', 'about', 'more', 'their', 'they', 'when', 'which', 'who',
        'why', 'out', 'use', 'new', 'one', 'get', 'also', 'than', 'then', 'them', 'these', 'those', 'best', 'top', 'home', 'page',
        'welcome', 'official', 'site', 'website', 'online', 'free', 'a', 'an', 'of', 'to', 'in', 'on', 'at', 'by', 'or', 'is', 'it', 'we', 'us'];

    private const CACHE_DAYS = 7;

    /** Remember a refused endpoint for this long before trying again. */
    private const UNAVAILABLE_MINUTES = 60;

    public function __construct(private readonly PinterestClient $client) {}

    /** The account research runs as: the default account if usable, else any active production, else sandbox. */
    /** Scope each research source needs. */
    private const SOURCE_SCOPES = ['terms' => 'ads:read', 'trends' => 'user_accounts:read'];

    /**
     * The account a research source runs as. Production returns real search data, the sandbox only test data,
     * so a production account that has the scope always wins; then the default account; then anything active.
     */
    public function account(User $user, string $source = 'trends'): ?PinterestAccount
    {
        $accounts = $user->pinterestAccounts()->where('status', 'active')->get();
        $usable = $accounts->reject(fn ($a) => Cache::has($this->unavailableKey($a, $source)));
        $scope = self::SOURCE_SCOPES[$source] ?? null;

        return $usable->first(fn ($a) => $a->environment === 'production' && $this->hasScope($a, $scope))
            ?? $usable->firstWhere('id', $user->preferences()->default_pinterest_account_id)
            ?? $usable->firstWhere('environment', 'production')
            ?? $usable->first()
            ?? $accounts->first();
    }

    /**
     * What research can do for this user right now (shown on the SEO dashboard).
     *
     * @return array{connected:bool, account_id:?int, account:?string, environment:?string, terms:string, trends:string, terms_environment:?string, trends_environment:?string, enabled:bool, region:string}
     */
    public function status(User $user): array
    {
        $state = function (string $source) use ($user) {
            $account = $this->account($user, $source);
            if (! $account) {
                return 'not_connected';
            }
            if (Cache::has($this->unavailableKey($account, $source))) {
                return 'unavailable';
            }

            return $this->hasScope($account, self::SOURCE_SCOPES[$source]) ? 'ok' : 'missing_scope';
        };
        $prefs = $user->preferences();
        $account = $this->account($user);

        return [
            'connected' => (bool) $account,
            'account_id' => $account?->id,
            'account' => $account?->username,
            'environment' => $account?->environment,
            'terms' => $state('terms'),
            'trends' => $state('trends'),
            // "sandbox" here means Pinterest only returns test data (it echoes the search term back).
            'terms_environment' => $this->account($user, 'terms')?->environment,
            'trends_environment' => $this->account($user, 'trends')?->environment,
            'enabled' => (bool) $prefs->keyword_research,
            'region' => $prefs->trends_region ?: 'US',
        ];
    }

    private function hasScope(PinterestAccount $account, ?string $scope): bool
    {
        $scopes = preg_split('/[\s,]+/', (string) $account->scopes, -1, PREG_SPLIT_NO_EMPTY);

        // Sandbox tokens do not report scopes; assume granted and let the API decide.
        return $scope === null || $scopes === [] || in_array($scope, $scopes, true);
    }

    /** @return list<string> */
    public function suggested(User $user, string $term, int $limit = 10): array
    {
        $term = $this->clean($term);
        if ($term === '') {
            return [];
        }

        $data = $this->call($user, 'terms', 'terms/suggested', ['term' => $term, 'limit' => min(10, max(1, $limit))]);

        return $this->strings($data ?? []);
    }

    /** @return list<string> */
    public function related(User $user, array $terms): array
    {
        $terms = array_values(array_filter(array_map(fn ($t) => $this->clean($t), $terms)));
        if ($terms === []) {
            return [];
        }

        $data = $this->call($user, 'terms', 'terms/related', ['terms' => implode(',', array_slice($terms, 0, 10))]);
        $out = [];
        foreach ($data['related_terms_list'] ?? [] as $group) {
            $out = array_merge($out, $this->strings($group['related_terms'] ?? []));
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $include  limit results to these keywords (max 50)
     * @return list<array{keyword:string, pct_growth_wow:?int, pct_growth_mom:?int, pct_growth_yoy:?int, time_series:array}>
     */
    public function trending(User $user, ?string $region = null, string $type = 'monthly', ?string $interest = null, array $include = [], int $limit = 50): array
    {
        $region = in_array($region, self::REGIONS, true) ? $region : ($user->preferences()->trends_region ?: 'US');
        $type = in_array($type, self::TREND_TYPES, true) ? $type : 'monthly';
        $query = array_filter([
            'interests' => in_array($interest, self::INTERESTS, true) ? $interest : null,
            'include_keywords' => $include ? implode(',', array_slice(array_values(array_unique(array_map(fn ($k) => $this->clean($k), $include))), 0, 50)) : null,
            'limit' => min(50, max(1, $limit)),
        ]);

        $data = $this->call($user, 'trends', 'trends/keywords/'.rawurlencode($region).'/top/'.$type, $query);

        return collect($data['trends'] ?? [])
            ->filter(fn ($t) => is_array($t) && filled($t['keyword'] ?? null))
            ->map(fn ($t) => [
                'keyword' => $this->clean($t['keyword']),
                'pct_growth_wow' => isset($t['pct_growth_wow']) ? (int) $t['pct_growth_wow'] : null,
                'pct_growth_mom' => isset($t['pct_growth_mom']) ? (int) $t['pct_growth_mom'] : null,
                'pct_growth_yoy' => isset($t['pct_growth_yoy']) ? (int) $t['pct_growth_yoy'] : null,
                'time_series' => is_array($t['time_series'] ?? null) ? $t['time_series'] : [],
            ])
            ->values()
            ->all();
    }

    /**
     * Full research for some seed phrases: suggested + related searches, filtered to the topic,
     * annotated with trend growth when Pinterest reports the keyword as trending.
     *
     * @param  list<string>  $seeds
     * @param  string  $topicText  text the keywords must relate to (page title, headings…); defaults to the seeds
     * @return list<array{keyword:string, sources:list<string>, growth:?int}>
     */
    public function research(User $user, array $seeds, string $topicText = ''): array
    {
        $seeds = array_values(array_unique(array_filter(array_map(fn ($s) => $this->clean($s), $seeds))));
        if ($seeds === []) {
            return [];
        }

        $found = [];
        $add = function (string $keyword, string $source) use (&$found) {
            $keyword = $this->clean($keyword);
            if ($keyword === '' || mb_strlen($keyword) > 80) {
                return;
            }
            $found[$keyword]['keyword'] = $keyword;
            $found[$keyword]['sources'][$source] = true;
        };

        // Two prefixes are enough; every call counts against Pinterest's daily budget on Trial access.
        foreach (array_slice($seeds, 0, 2) as $seed) {
            foreach ($this->suggested($user, $seed) as $k) {
                $add($k, 'suggested');
            }
        }
        foreach ($this->related($user, array_slice($seeds, 0, 3)) as $k) {
            $add($k, 'related');
        }

        // Keep only keywords about this topic; Pinterest suggestions can drift ("pos" → "poses").
        $topic = $this->tokens($topicText !== '' ? $topicText.' '.implode(' ', $seeds) : implode(' ', $seeds));
        // Single words ("cloud", "wash") are too broad to target; Pinterest searches worth using are phrases.
        $found = array_filter($found, fn ($row) => count($this->tokens($row['keyword'])) >= 2
            && array_intersect($this->tokens($row['keyword']), $topic) !== []);
        if ($found === []) {
            return [];
        }

        $growth = [];
        foreach ($this->trending($user, null, 'growing', null, array_keys($found)) as $t) {
            $growth[$t['keyword']] = $t['pct_growth_mom'] ?? $t['pct_growth_wow'];
        }

        $rows = array_map(fn ($row) => [
            'keyword' => $row['keyword'],
            'sources' => array_merge(array_keys($row['sources']), isset($growth[$row['keyword']]) ? ['trending'] : []),
            'growth' => $growth[$row['keyword']] ?? null,
        ], array_values($found));

        // Trending first, then keywords confirmed by several sources, then by topic overlap.
        usort($rows, fn ($a, $b) => [($b['growth'] !== null), count($b['sources']), count(array_intersect($this->tokens($b['keyword']), $topic))]
            <=> [($a['growth'] !== null), count($a['sources']), count(array_intersect($this->tokens($a['keyword']), $topic))]);

        return array_slice($rows, 0, 25);
    }

    /**
     * Seed phrases for a page: the main phrase of its title (with and without the last word) plus its
     * most repeated topic word across title, headings and description.
     *
     * @return list<string>
     */
    public function seedsFromPage(ExtractedPageData $page): array
    {
        $title = (string) ($page->og_title ?: $page->title);
        $title = trim(preg_split('/\s+[|\-–—:]\s+/u', $title)[0] ?? $title);
        $words = array_values(array_filter($this->words($title), fn ($w) => ! in_array($w, self::STOPWORDS, true)));

        $seeds = [];
        if ($words) {
            $seeds[] = implode(' ', array_slice($words, 0, 3));
            if (count($words) > 2) {
                $seeds[] = implode(' ', array_slice($words, 0, 2));
            }
        }

        $text = $title.' '.collect($page->headings ?? [])->take(10)->pluck('text')->implode(' ').' '.$page->meta_description;
        $counts = array_count_values(array_filter($this->words($text), fn ($w) => mb_strlen($w) > 2 && ! in_array($w, self::STOPWORDS, true) && ! is_numeric($w)));
        arsort($counts);
        if ($top = array_key_first($counts)) {
            $seeds[] = $top;
        }

        return array_values(array_unique($seeds));
    }

    /** Significant lower-case words, with a simple plural fold so "systems" matches "system". */
    public function tokens(string $text): array
    {
        return array_values(array_unique(array_map(
            fn ($w) => mb_strlen($w) > 3 ? preg_replace('/(?<!s)s$/u', '', $w) : $w,
            array_filter($this->words($text), fn ($w) => mb_strlen($w) > 1 && ! in_array($w, self::STOPWORDS, true)),
        )));
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
    }

    private function clean(string $term): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(strip_tags($term))));
    }

    /** @return list<string> */
    private function strings(array $items): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($s) => is_string($s) ? $this->clean($s) : '', $items))));
    }

    /** Cached API call; returns null when the source is unavailable or failed. */
    private function call(User $user, string $source, string $path, array $query): ?array
    {
        $account = $this->account($user, $source);
        if (! $account || ! $user->preferences()->keyword_research || Cache::has($this->unavailableKey($account, $source))) {
            return null;
        }

        $key = 'kw:'.$account->id.':'.md5($path.'?'.http_build_query($query));
        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        try {
            // Low priority: research stops before publishing runs out of Pinterest API budget.
            $data = $this->client->get($account, $path, $query, PinterestRateLimiter::PRIORITY_LOW);
        } catch (PinterestApiException $e) {
            // 401/403: the app or token is not allowed this endpoint (e.g. missing ads:read). Back off quietly.
            if (in_array($e->status, [401, 403], true) || ! $e->retryable) {
                Cache::put($this->unavailableKey($account, $source), $e->getMessage(), now()->addMinutes(self::UNAVAILABLE_MINUTES));
                ActivityLogger::log($user->id, 'keyword_research_unavailable', 'Pinterest keyword research ('.$source.') is not available: '.Str::limit($e->getMessage(), 200), 'warning', details: $e->developerDetails());
            }

            return null;
        } catch (\Throwable) {
            return null;
        }

        Cache::put($key, $data, now()->addDays(self::CACHE_DAYS));

        return $data;
    }

    private function unavailableKey(PinterestAccount $account, string $source): string
    {
        return "kw_unavailable:{$account->id}:{$source}";
    }
}
