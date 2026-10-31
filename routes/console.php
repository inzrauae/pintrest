<?php

use App\Services\TaskStatusService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Lets the dashboard show whether the scheduler (automation) is running.
Schedule::call(fn () => Cache::put(TaskStatusService::SCHEDULER_HEARTBEAT, time(), 600))->everyMinute()->name('scheduler-heartbeat');

Schedule::command('pins:dispatch-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('seo:autopilot')->hourly()->withoutOverlapping();
Schedule::command('pins:sync-analytics')->everySixHours()->withoutOverlapping();
Schedule::command('pins:resume-waiting')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('pins:auto-publish')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('pinterest:refresh-tokens')->daily();
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
