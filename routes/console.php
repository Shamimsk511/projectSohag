<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('schedules:generate')->dailyAt('18:07')->timezone(config('app.display_timezone'))->withoutOverlapping();
Schedule::command('schedules:mark-missed')->dailyAt('00:17')->timezone(config('app.display_timezone'))->withoutOverlapping();
Schedule::command('photos:prune-temp')->dailyAt('03:27')->timezone(config('app.display_timezone'))->withoutOverlapping();
