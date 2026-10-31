<?php

namespace App\Services;

use App\Jobs\PublishPinJob;
use App\Models\GeneratedPin;
use App\Models\PinterestAccount;
use App\Models\PinterestBoard;
use App\Models\PublishingJob;
use App\Services\Pinterest\PinPublisher;
use App\Services\Pinterest\PinterestRateLimiter;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Queues Pins for publication (now or scheduled) after preflight checks.
 */
class PublishingService
{
    public function __construct(private readonly PinPublisher $publisher) {}

    /**
     * One-click publish: creates the Pin on Pinterest during the request so the user sees the result at once.
     * A temporary failure (rate limit, network) hands the retry to the queue worker.
     */
    public function publishNow(GeneratedPin $pin, bool $allowDuplicate = false): PublishingJob
    {
        $job = $this->queue($pin, 'manual', $allowDuplicate, sync: true);

        @set_time_limit(180);
        // Run the job's logic in this request (no queue involved); a retryable error leaves the job "queued".
        app()->call([new PublishPinJob($job->id, $allowDuplicate), 'handle']);

        $job->refresh();
        if ($job->status === 'queued') {
            PublishPinJob::dispatch($job->id, $allowDuplicate)->delay($job->next_attempt_at ?? now()->addMinute());
        }

        return $job;
    }

    /**
     * On Trial access a production account can never create Pins, so publishing (manual, scheduled or automatic)
     * goes through the user's sandbox account instead of failing. No-op on Standard access or without a sandbox account.
     */
    private function sandboxIfTrial(GeneratedPin $pin): GeneratedPin
    {
        $pin->loadMissing('account');
        if ($pin->account?->environment !== 'production' || app(PinterestRateLimiter::class)->level() !== 'trial' || ! $pin->isEditable()) {
            return $pin;
        }
        $hasSandbox = PinterestAccount::where('user_id', $pin->user_id)->where('environment', 'sandbox')->where('status', 'active')->exists();

        return $hasSandbox ? $this->moveToSandbox($pin)->load('account') : $pin;
    }

    /**
     * Trial access can only create Pins in the sandbox: move a Pin set to a production account onto the user's
     * sandbox account, on the sandbox board with the same name, else the default/first sandbox board.
     */
    public function moveToSandbox(GeneratedPin $pin): GeneratedPin
    {
        $sandbox = PinterestAccount::where('user_id', $pin->user_id)->where('environment', 'sandbox')->where('status', 'active')->first();
        if (! $sandbox) {
            throw ValidationException::withMessages(['publish' => ['Connect a sandbox account on the Pinterest Accounts page ("Connect sandbox") to publish while your app has Trial access.']]);
        }
        if ($pin->pinterest_account_id === $sandbox->id) {
            return $pin;
        }

        $currentName = $pin->board_id ? PinterestBoard::where('pinterest_account_id', $pin->pinterest_account_id)->where('board_id', $pin->board_id)->value('name') : null;
        $boards = PinterestBoard::where('pinterest_account_id', $sandbox->id)->orderBy('name')->get(['board_id', 'name']);
        $default = $pin->user->preferences()->default_board_id;
        $board = ($currentName ? $boards->first(fn ($b) => mb_strtolower($b->name) === mb_strtolower($currentName)) : null)
            ?? $boards->firstWhere('board_id', $default)
            ?? $boards->first();
        if (! $board) {
            throw ValidationException::withMessages(['publish' => ['Your sandbox account has no boards yet. Open the Pin and create one with “+ New board…”.']]);
        }

        $pin->update(['pinterest_account_id' => $sandbox->id, 'board_id' => $board->board_id]);
        ActivityLogger::log($pin->user_id, 'pin_moved_to_sandbox', "Moved \"{$pin->title}\" to the sandbox account (board \"{$board->name}\") because the app has Trial access.", pin: $pin);

        return $pin->fresh();
    }

    public function queue(GeneratedPin $pin, string $trigger = 'manual', bool $allowDuplicate = false, bool $sync = false): PublishingJob
    {
        $pin = $this->sandboxIfTrial($pin);
        $errors = $this->publisher->preflight($pin, $allowDuplicate);
        if ($errors) {
            throw ValidationException::withMessages(['publish' => $errors]);
        }

        $job = DB::transaction(function () use ($pin, $trigger) {
            // Atomic status transition prevents double-submission from repeated clicks.
            $updated = GeneratedPin::whereKey($pin->id)
                ->whereIn('status', ['draft', 'approved', 'failed', 'scheduled'])
                ->update([
                    'status' => 'publishing',
                    'approved_at' => $pin->approved_at ?? now(),
                    'error_message' => null,
                ]);
            if (! $updated) {
                throw ValidationException::withMessages(['publish' => ['This Pin is already being published.']]);
            }

            return PublishingJob::create([
                'user_id' => $pin->user_id,
                'generated_pin_id' => $pin->id,
                'trigger' => $trigger,
                'status' => 'queued',
            ]);
        });

        if (! $sync) {
            PublishPinJob::dispatch($job->id, $allowDuplicate);
        }

        return $job;
    }

    public function schedule(GeneratedPin $pin, \DateTimeInterface $at, bool $allowDuplicate = false): GeneratedPin
    {
        $pin = $this->sandboxIfTrial($pin);
        $errors = $this->publisher->preflight($pin, $allowDuplicate, checkLimit: false);
        if ($errors) {
            throw ValidationException::withMessages(['schedule' => $errors]);
        }

        DB::transaction(function () use ($pin, $at) {
            $pin->schedule()->updateOrCreate([], [
                'user_id' => $pin->user_id,
                'scheduled_at' => $at,
                'status' => 'scheduled',
            ]);
            $pin->update(['status' => 'scheduled', 'approved_at' => $pin->approved_at ?? now(), 'error_message' => null]);
        });

        return $pin->fresh('schedule');
    }

    public function unschedule(GeneratedPin $pin): GeneratedPin
    {
        $pin->schedule?->update(['status' => 'cancelled']);
        if ($pin->status === 'scheduled') {
            $pin->update(['status' => 'approved']);
        }

        return $pin->fresh('schedule');
    }
}
