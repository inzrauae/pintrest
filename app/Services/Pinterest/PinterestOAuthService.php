<?php

namespace App\Services\Pinterest;

use App\Models\PinterestAccount;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Official Pinterest OAuth 2.0 (authorization code) flow.
 * Docs: https://developers.pinterest.com/docs/getting-started/authentication-and-scopes/
 */
class PinterestOAuthService
{
    public function isConfigured(): bool
    {
        return filled(config('services.pinterest.client_id')) && filled(config('services.pinterest.client_secret'));
    }

    /** Builds the consent URL and stores a single-use CSRF state bound to the user. */
    public function authorizationUrl(User $user, string $environment = 'production'): string
    {
        $state = Str::random(48);
        Cache::put("pinterest_oauth_state:{$state}", ['user_id' => $user->id, 'environment' => $environment], now()->addMinutes(10));

        return config('services.pinterest.authorize_url').'?'.http_build_query([
            'client_id' => config('services.pinterest.client_id'),
            'redirect_uri' => config('services.pinterest.redirect_uri'),
            'response_type' => 'code',
            'scope' => config('services.pinterest.scopes'),
            'state' => $state,
        ]);
    }

    /** @return array{user_id:int, environment:string}|null */
    public function consumeState(string $state): ?array
    {
        return $state !== '' ? Cache::pull("pinterest_oauth_state:{$state}") : null;
    }

    public function handleCallback(User $user, string $code, string $environment): PinterestAccount
    {
        $token = $this->tokenRequest($environment, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.pinterest.redirect_uri'),
            // Request a continuous refresh token (Pinterest's current refresh model).
            'continuous_refresh' => 'true',
        ]);

        $profile = $this->fetchProfile($environment, $token['access_token']);

        return DB::transaction(function () use ($user, $token, $profile, $environment) {
            $account = PinterestAccount::firstOrNew([
                'user_id' => $user->id,
                'pinterest_user_id' => $profile['id'] ?? $profile['username'] ?? null,
                'environment' => $environment,
            ]);

            $account->fill([
                'username' => $profile['username'] ?? null,
                'profile_image' => $profile['profile_image'] ?? null,
                'account_type' => $profile['account_type'] ?? null,
                'connected_at' => now(),
                'status' => 'active',
                'last_error' => null,
            ]);
            $this->applyToken($account, $token);
            $account->save();

            ActivityLogger::log($user->id, 'pinterest_connected', 'Connected Pinterest account @'.($account->username ?? 'unknown').($environment === 'sandbox' ? ' (sandbox)' : ''), accountId: $account->id);

            return $account;
        });
    }

    public function refresh(PinterestAccount $account): PinterestAccount
    {
        if (! $account->refresh_token) {
            $account->update(['status' => 'expired', 'last_error' => 'No refresh token available.']);
            throw new PinterestApiException('Your Pinterest authorization has expired. Please reconnect the account.', status: 401, authExpired: true);
        }

        try {
            $token = $this->tokenRequest($account->environment, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
            ]);
        } catch (PinterestApiException $e) {
            if (in_array($e->status, [400, 401], true)) {
                $account->update(['status' => 'expired', 'last_error' => json_encode($e->apiError)]);
                ActivityLogger::log($account->user_id, 'pinterest_token_expired', 'Pinterest authorization expired; reconnect required.', 'warning', details: $e->developerDetails(), httpStatus: $e->status, accountId: $account->id);

                throw new PinterestApiException('Your Pinterest authorization has expired. Please reconnect the account.', status: 401, apiError: $e->apiError, authExpired: true);
            }
            throw $e;
        }

        $this->applyToken($account, $token);
        $account->status = 'active';
        $account->last_error = null;
        $account->save();

        return $account;
    }

    private function applyToken(PinterestAccount $account, array $token): void
    {
        $account->access_token = $token['access_token'];
        // Continuous refresh returns a new refresh token; keep the old one if none is returned.
        if (! empty($token['refresh_token'])) {
            $account->refresh_token = $token['refresh_token'];
        }
        $account->token_expires_at = isset($token['expires_in']) ? now()->addSeconds((int) $token['expires_in']) : null;
        if (isset($token['refresh_token_expires_in'])) {
            $account->refresh_token_expires_at = now()->addSeconds((int) $token['refresh_token_expires_in']);
        }
        if (! empty($token['scope'])) {
            $account->scopes = $token['scope'];
        }
    }

    private function tokenRequest(string $environment, array $form): array
    {
        try {
            $response = Http::asForm()
                ->withBasicAuth(config('services.pinterest.client_id'), config('services.pinterest.client_secret'))
                ->timeout((int) config('services.pinterest.timeout'))
                ->acceptJson()
                ->post(PinterestClient::baseUrl($environment).'/oauth/token', $form);
        } catch (ConnectionException $e) {
            throw new PinterestApiException('Could not reach Pinterest. Please try again.', retryable: true, previous: $e);
        }

        if ($response->failed() || ! $response->json('access_token')) {
            throw PinterestApiException::fromResponse($response->status() ?: 500, $response->json());
        }

        return $response->json();
    }

    private function fetchProfile(string $environment, string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->timeout((int) config('services.pinterest.timeout'))
            ->acceptJson()
            ->get(PinterestClient::baseUrl($environment).'/user_account');

        if ($response->failed()) {
            throw PinterestApiException::fromResponse($response->status(), $response->json());
        }

        return $response->json() ?? [];
    }
}
