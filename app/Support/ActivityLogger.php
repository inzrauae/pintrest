<?php

namespace App\Support;

use App\Models\GeneratedPin;
use App\Models\PublicationLog;
use Illuminate\Support\Facades\Log;

/**
 * Writes the audit trail shown in "Recent activity" and the developer log view.
 * `message` is user-facing; `details` is developer-facing and must never contain secrets.
 */
class ActivityLogger
{
    private const SECRET_KEYS = ['access_token', 'refresh_token', 'client_secret', 'authorization', 'api_key', 'password'];

    public static function log(
        ?int $userId,
        string $event,
        string $message,
        string $level = 'info',
        ?GeneratedPin $pin = null,
        array $details = [],
        ?int $httpStatus = null,
        ?int $accountId = null,
    ): PublicationLog {
        $details = self::redact($details);

        if ($level !== 'info') {
            Log::channel(config('logging.default'))->log($level === 'error' ? 'error' : 'warning', "[pinbot] {$event}: {$message}", [
                'user_id' => $userId,
                'pin_id' => $pin?->id,
                'details' => $details,
            ]);
        }

        return PublicationLog::create([
            'user_id' => $userId,
            'generated_pin_id' => $pin?->id,
            'pinterest_account_id' => $accountId ?? $pin?->pinterest_account_id,
            'level' => $level,
            'event' => $event,
            'message' => mb_substr($message, 0, 500),
            'details' => $details ?: null,
            'http_status' => $httpStatus,
        ]);
    }

    public static function security(string $event, array $context = []): void
    {
        Log::warning("[security] {$event}", self::redact($context));
    }

    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
