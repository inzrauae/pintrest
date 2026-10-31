<?php

namespace App\Console\Commands;

use App\Models\GeneratedPin;
use App\Models\UserPreference;
use App\Services\PublishingService;
use App\Services\UsageLimiter;
use App\Support\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Opt-in automation: publishes Pins the user has APPROVED (never unreviewed drafts),
 * one per user per spacing interval, inside the publishing window and daily limit.
 */
class AutoPublishApprovedPins extends Command
{
    protected $signature = 'pins:auto-publish';

    protected $description = 'Publish approved Pins for users who explicitly enabled automatic publishing';

    public function handle(PublishingService $publishing, UsageLimiter $limiter): int
    {
        $prefs = UserPreference::with('user')
            ->where('auto_publish', true)
            ->whereNotNull('auto_publish_acknowledged_at')
            ->where('automation_paused', false)
            ->get();

        foreach ($prefs as $pref) {
            $user = $pref->user;
            if (! $pref->withinPublishingWindow(now()) || ! $limiter->canPublish($user)) {
                continue;
            }

            $last = GeneratedPin::where('user_id', $user->id)->max('published_at');
            if ($last && now()->diffInMinutes($last, true) < $pref->auto_publish_spacing_minutes) {
                continue;
            }

            $pin = GeneratedPin::where('user_id', $user->id)
                ->where('status', 'approved')
                ->whereDoesntHave('schedule', fn ($q) => $q->where('status', 'scheduled'))
                ->orderBy('approved_at')
                ->first();
            if (! $pin) {
                continue;
            }

            try {
                $publishing->queue($pin, 'automation');
            } catch (ValidationException $e) {
                $message = collect($e->errors())->flatten()->implode(' ');
                $pin->update(['status' => 'failed', 'error_message' => $message]);
                ActivityLogger::log($user->id, 'auto_publish_skipped', 'Automatic publishing skipped a Pin: '.$message, 'warning', $pin);
            }
        }

        return self::SUCCESS;
    }
}
