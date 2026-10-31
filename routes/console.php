<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('pins:dispatch-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('pins:auto-publish')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('pinterest:refresh-tokens')->daily();
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
