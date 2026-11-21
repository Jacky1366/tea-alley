<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Backup for Stripe's "expired" webhook. Needs the scheduler: `php artisan schedule:work`
// locally, Cloud's Scheduler toggle on Laravel Cloud (docs/cloud-deploy.md), or a cron entry
// for `schedule:run` elsewhere.
Schedule::command('orders:cancel-stale')->everyFiveMinutes();
