<?php

namespace App\Services\Pinterest;

use App\Models\PinterestAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Client-side budget for Pinterest's published rate limits, so PinBot slows down before Pinterest says no.
 *
 * Trial access counts per app per UTC day; Standard access per account per minute. Each request counts
 * against the universal "all" budget and its endpoint category. Low-priority work (keyword research,
 * analytics) stops at a share of each budget so publishing is never starved. Sandbox and production
 * are counted separately.
 */
class PinterestRateLimiter
{
    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_LOW = 'low';

    /** Endpoint category per Pinterest's API reference (x-ratelimit-category). */
    public static function category(string $method, string $path): string
    {
        $path = trim(parse_url($path, PHP_URL_PATH) ?: $path, '/');

        return match (true) {
            str_starts_with($path, 'trends/') => 'trends_read',
            str_starts_with($path, 'terms/') => 'ads_read',
            (bool) preg_match('#^pins/[^/]+/analytics$#', $path), str_starts_with($path, 'user_account/analytics') => 'org_analytics',
            strtolower($method) !== 'get' => 'org_write',
            default => 'org_read',
        };
    }

    /** Most Pins a user may set PinBot to publish per day: Pinterest's daily Pin-creation limit on Trial access. */
    public function maxDailyPublishes(): int
    {
        return $this->level() === 'trial'
            ? min(self::APP_MAX_DAILY_PUBLISHES, (int) $this->limit('org_write'))
            : self::APP_MAX_DAILY_PUBLISHES;
    }

    /** PinBot's own ceiling on Standard access, where Pinterest's limit is per minute. */
    public const APP_MAX_DAILY_PUBLISHES = 500;

    public function level(): string
    {
        return config('pinbot.pinterest_limits.access_level') === 'standard' ? 'standard' : 'trial';
    }

    /**
     * Counts one request, or throws a retryable 429-style exception when the budget is used up.
     */
    public function reserve(PinterestAccount $account, string $category, string $priority = self::PRIORITY_NORMAL): void
    {
        $share = $priority === self::PRIORITY_LOW ? (float) config('pinbot.pinterest_limits.low_priority_share') : 1.0;

        foreach (array_unique(['all', $category]) as $bucket) {
            $limit = $this->limit($bucket);
            if ($limit === null) {
                continue;
            }
            $cap = max(1, (int) floor($limit * $share));
            if ($this->used($account, $bucket) >= $cap) {
                $window = $this->level() === 'trial' ? 'daily' : 'per-minute';
                $what = $bucket === 'all' ? 'request' : str_replace('_', ' ', $bucket);
                $reserved = $share < 1 ? ' (the rest is kept for publishing)' : '';
                throw new PinterestApiException(
                    "Pinterest {$window} {$what} limit reached{$reserved}. It continues automatically at ".$this->resetsAt()->toDayDateTimeString().' UTC.',
                    status: 429,
                    retryable: true,
                    retryAfter: max(1, now()->diffInSeconds($this->resetsAt(), true)),
                );
            }
        }

        foreach (array_unique(['all', $category]) as $bucket) {
            $key = $this->key($account, $bucket);
            Cache::add($key, 0, $this->resetsAt()->copy()->addMinute());
            Cache::increment($key);
        }
    }

    public function used(PinterestAccount $account, string $bucket): int
    {
        return (int) Cache::get($this->key($account, $bucket), 0);
    }

    public function limit(string $bucket): ?int
    {
        $value = config("pinbot.pinterest_limits.{$this->level()}.{$bucket}");

        return $value === null ? null : (int) $value;
    }

    /** End of the current counting window (UTC). */
    public function resetsAt(): Carbon
    {
        return $this->level() === 'trial' ? now('UTC')->endOfDay() : now('UTC')->endOfMinute();
    }

    /**
     * Usage per bucket for the dashboard.
     *
     * @return array{level:string, window:string, resets_at:string, buckets:list<array{bucket:string, used:int, limit:int}>}
     */
    public function usage(PinterestAccount $account): array
    {
        $buckets = [];
        foreach (array_keys(config("pinbot.pinterest_limits.{$this->level()}")) as $bucket) {
            $buckets[] = ['bucket' => $bucket, 'used' => $this->used($account, $bucket), 'limit' => $this->limit($bucket)];
        }

        return [
            'level' => $this->level(),
            'window' => $this->level() === 'trial' ? 'day' : 'minute',
            'resets_at' => $this->resetsAt()->toIso8601String(),
            'buckets' => $buckets,
        ];
    }

    private function key(PinterestAccount $account, string $bucket): string
    {
        // Trial limits are per app (all accounts share them); Standard limits are per user.
        return $this->level() === 'trial'
            ? "pinterest_rl:{$account->environment}:app:{$bucket}:".now('UTC')->format('Ymd')
            : "pinterest_rl:{$account->environment}:{$account->id}:{$bucket}:".now('UTC')->format('YmdHi');
    }
}
