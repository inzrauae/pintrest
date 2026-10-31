<?php

namespace App\Services\Pinterest;

use App\Models\PinterestAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over Pinterest API v5 with automatic token refresh and error mapping.
 */
class PinterestClient
{
    public function __construct(
        private readonly PinterestOAuthService $oauth,
        private readonly PinterestRateLimiter $limiter,
    ) {}

    public static function baseUrl(string $environment): string
    {
        return rtrim($environment === 'sandbox'
            ? config('services.pinterest.sandbox_api_url')
            : config('services.pinterest.api_url'), '/');
    }

    /**
     * @param  string  $priority  PinterestRateLimiter::PRIORITY_LOW for background work (research, analytics)
     */
    public function get(PinterestAccount $account, string $path, array $query = [], string $priority = PinterestRateLimiter::PRIORITY_NORMAL): array
    {
        return $this->send($account, 'get', $path, $query, priority: $priority);
    }

    public function post(PinterestAccount $account, string $path, array $body): array
    {
        return $this->send($account, 'post', $path, $body);
    }

    private function send(PinterestAccount $account, string $method, string $path, array $data, bool $retried = false, string $priority = PinterestRateLimiter::PRIORITY_NORMAL): array
    {
        if ($account->status === 'expired' || $account->status === 'revoked') {
            throw new PinterestApiException('This Pinterest account needs to be reconnected.', status: 401, authExpired: true);
        }

        $this->assertNotRateLimited($account);
        if (! $retried) {
            $this->limiter->reserve($account, PinterestRateLimiter::category($method, $path), $priority);
        }

        // Refresh proactively, serialised per account so parallel workers don't race on token rotation.
        if ($account->tokenExpiresSoon()) {
            Cache::lock("pinterest_refresh:{$account->id}", 30)->block(20, function () use ($account) {
                $account->refresh();
                if ($account->tokenExpiresSoon()) {
                    $this->oauth->refresh($account);
                }
            });
        }

        try {
            $response = Http::withToken($account->access_token)
                ->timeout((int) config('services.pinterest.timeout'))
                ->acceptJson()
                ->{$method}(self::baseUrl($account->environment).'/'.ltrim($path, '/'), $data);
        } catch (ConnectionException $e) {
            throw new PinterestApiException('Could not reach Pinterest. The action will be retried.', retryable: true, previous: $e);
        }

        if ($response->status() === 401 && ! $retried && $account->refresh_token) {
            $this->oauth->refresh($account);

            return $this->send($account->refresh(), $method, $path, $data, true, $priority);
        }

        $this->rememberExhaustedWindow($account, $response);

        if ($response->failed()) {
            $this->rememberRateLimit($account, $response);
            $e = PinterestApiException::fromResponse($response->status(), $response->json(), $response->header('Retry-After'));
            if ($e->authExpired) {
                $account->update(['status' => 'expired', 'last_error' => json_encode($e->apiError)]);
            }

            throw $e;
        }

        return $response->json() ?? [];
    }

    private function assertNotRateLimited(PinterestAccount $account): void
    {
        $until = Cache::get("pinterest_rate_limited:{$account->id}");
        if ($until && $until > time()) {
            throw new PinterestApiException('Pinterest rate limit reached. The action will be retried automatically.', status: 429, retryable: true, retryAfter: $until - time());
        }
    }

    /**
     * Pinterest reports its own tally ("x-ratelimit-remaining: 0", "x-ratelimit-reset: <seconds>").
     * When nothing is left, pause this account until the reset instead of waiting for a 429.
     */
    private function rememberExhaustedWindow(PinterestAccount $account, Response $response): void
    {
        $remaining = $response->header('x-ratelimit-remaining');
        if ($remaining === '' || ! preg_match('/^\s*(\d+)/', $remaining, $m) || (int) $m[1] > 0) {
            return;
        }
        $wait = max(1, min((int) ($response->header('x-ratelimit-reset') ?: 60), 3600));
        Cache::put("pinterest_rate_limited:{$account->id}", time() + $wait, $wait);
    }

    private function rememberRateLimit(PinterestAccount $account, Response $response): void
    {
        if ($response->status() !== 429) {
            return;
        }
        $wait = (int) ($response->header('Retry-After') ?: $response->header('X-RateLimit-Reset') ?: 60);
        $wait = max(10, min($wait, 3600));
        Cache::put("pinterest_rate_limited:{$account->id}", time() + $wait, $wait);
    }
}
