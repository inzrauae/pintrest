<?php

namespace App\Services;

use App\Jobs\PublishPinJob;
use App\Models\GeneratedPin;
use App\Models\PublishingJob;
use App\Services\Pinterest\PinPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Queues Pins for publication (now or scheduled) after preflight checks.
 */
class PublishingService
{
    public function __construct(private readonly PinPublisher $publisher) {}

    public function queue(GeneratedPin $pin, string $trigger = 'manual', bool $allowDuplicate = false): PublishingJob
    {
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

        PublishPinJob::dispatch($job->id, $allowDuplicate);

        return $job;
    }

    public function schedule(GeneratedPin $pin, \DateTimeInterface $at, bool $allowDuplicate = false): GeneratedPin
    {
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
