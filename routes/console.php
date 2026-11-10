<?php

use Illuminate\Support\Facades\Schedule;

/*
 * The nightly backup. BACKUP_SCHEDULE is a cron expression in UTC; the default
 * is 03:00 IST. A run that is still going when the next one is due is never
 * doubled up; the lock expires after 12 hours in case a run dies holding it.
 */
Schedule::command('backup:databases')
    ->cron(config('backup.schedule'))
    ->timezone('UTC')
    ->withoutOverlapping(720)
    ->runInBackground();
