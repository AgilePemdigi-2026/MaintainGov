<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recover analysis batches stuck in "Diproses" every 5 minutes.
// The command itself is idempotent and safe to overlap.
Schedule::command('analysis:recover-stale --timeout=300')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
