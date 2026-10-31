<?php

namespace App\Services\Pinterest;

use RuntimeException;
use Throwable;

/**
 * Carries both a simple user-facing message and the raw Pinterest error for developer logs.
 */
class PinterestApiException extends RuntimeException
{
    public function __construct(
        string $userMessage,
        public readonly ?int $status = null,
        public readonly array $apiError = [],
        public readonly bool $retryable = false,
        public readonly bool $authExpired = false,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($userMessage, 0, $previous);
    }

    public const TRIAL_PRODUCTION_MESSAGE = 'Your Pinterest app has Trial access, which can only publish to the sandbox (Pins visible only to you). Publish with your sandbox account, or apply for Standard access at developers.pinterest.com and then set PINTEREST_ACCESS_LEVEL=standard.';

    public static function fromResponse(int $status, ?array $body, ?string $retryAfter = null): self
    {
        $body ??= [];
        $apiMessage = (string) ($body['message'] ?? '');

        $userMessage = match (true) {
            // Pinterest code 29: "Apps with Trial access may not create Pins in production".
            (int) ($body['code'] ?? 0) === 29 => self::TRIAL_PRODUCTION_MESSAGE,
            $status === 401 =>'Your Pinterest authorization has expired or was revoked. Please reconnect the account.',
            $status === 403 => 'Pinterest denied this action. The app or account may lack the required permission (e.g. pins:write, or Standard API access for production publishing).',
            $status === 404 => 'Pinterest could not find the board or resource. Refresh your boards and try again.',
            $status === 429 => 'Pinterest rate limit reached. The Pin will be retried automatically.',
            $status >= 500 => 'Pinterest is temporarily unavailable. The Pin will be retried automatically.',
            $status === 400 => 'Pinterest rejected the Pin: '.($apiMessage !== '' ? $apiMessage : 'invalid data.'),
            default => 'Pinterest returned an unexpected error (HTTP '.$status.').',
        };

        return new self(
            $userMessage,
            status: $status,
            apiError: $body,
            retryable: $status === 429 || $status >= 500,
            authExpired: $status === 401,
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    public function developerDetails(): array
    {
        return ['status' => $this->status, 'pinterest_error' => $this->apiError];
    }
}
