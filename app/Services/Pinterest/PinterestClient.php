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
    public function __construct(private readonly PinterestOAuthService $oauth) {}

    public static function baseUrl(string $environment): string
    {
        return rtrim($environment === 'sandbox'
            ? config('services.pinterest.sandbox_api_url')
            : config('services.pinterest.api_url'), '/');
    }

    public function get(PinterestAccount $account, string $path, array $query = []): array
    {
        return $this->send($account, 'get', $path, $query);
    }

    public function post(PinterestAccount $account, string $path, array $body): array
    {
        return $this->send($account, 'post', $path, $body);
    }

    private function send(PinterestAccount $account, string $method, string $path, array $data, bool $retried = false): array
    {
        if ($account->status === 'expired' || $account->status === 'revoked') {
            throw new PinterestApiException('This Pinterest account needs to be reconnected.', status: 401, authExpired: true);
        }

        $this->assertNotRateLimited($account);

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

            return $this->send($account->refresh(), $method, $path, $data, true);
        }

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
