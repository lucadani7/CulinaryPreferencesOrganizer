<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The ECB publishes its reference rates on working days around 16:00 CET.
// Runs with `php artisan schedule:work` (or a cron calling `schedule:run` every minute).
Schedule::command('fx:refresh')->weekdays()->at('16:30')->timezone('Europe/Berlin');
