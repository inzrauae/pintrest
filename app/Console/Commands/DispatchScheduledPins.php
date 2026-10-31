<?php

namespace App\Console\Commands;

use App\Models\ScheduledPin;
use App\Services\PublishingService;
use App\Services\UsageLimiter;
use App\Support\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Pinterest's public API has no "publish later" parameter, so scheduling is done here:
 * due Pins are queued for publication when the user's window, limits and pause state allow.
 */
class DispatchScheduledPins extends Command
{
    protected $signature = 'pins:dispatch-scheduled';

    protected $description = 'Queue scheduled Pins whose publish time has arrived';

    public function handle(PublishingService $publishing, UsageLimiter $limiter): int
    {
        $due = ScheduledPin::with(['pin', 'user.preference'])
            ->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(200)
            ->get();

        $queued = 0;
        foreach ($due as $scheduled) {
            $user = $scheduled->user;
            $prefs = $user->preferences();

            if ($prefs->automation_paused || ! $prefs->withinPublishingWindow(now()) || ! $limiter->canPublish($user)) {
                continue; // stays scheduled; picked up on a later run
            }
            if (! $scheduled->pin || $scheduled->pin->status !== 'scheduled') {
                $scheduled->update(['status' => 'cancelled']);

                continue;
            }

            try {
                // Mark first: with a sync queue the publish completes inside queue() and sets "published".
                $scheduled->update(['status' => 'dispatched']);
                $publishing->queue($scheduled->pin, 'scheduled');
                $queued++;
            } catch (ValidationException $e) {
                $message = collect($e->errors())->flatten()->implode(' ');
                $scheduled->update(['status' => 'failed']);
                $scheduled->pin->update(['status' => 'failed', 'error_message' => $message]);
                ActivityLogger::log($user->id, 'schedule_failed', 'Scheduled Pin could not be published: '.$message, 'error', $scheduled->pin);
            }
        }

        $this->info("Queued {$queued} scheduled Pin(s).");

        return self::SUCCESS;
    }
}
