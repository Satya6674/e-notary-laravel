<?php

/*
| Scheduler safe mode. ON unless SCHEDULER_SAFE_MODE=false is set explicitly.
| ON:  only the heartbeat, the temporary-access expiry audit and appointment reminders run.
| OFF: all seven tasks in App\Console\ScheduledTasks run (adds the cleanup, deletion and expiry tasks).
| Turning it off needs the owner's approval. See App\Console\ScheduledTasks for the full list.
*/

return [
    'safe_mode' => filter_var(env('SCHEDULER_SAFE_MODE', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
];
