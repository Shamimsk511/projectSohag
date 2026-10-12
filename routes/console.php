<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$onScheduleFailure = fn (string $command): Closure => fn () => Log::critical('Scheduled command failed', ['command' => $command]);

Schedule::command('schedules:generate')->dailyAt('18:07')->timezone(config('app.display_timezone'))->withoutOverlapping()
    ->onFailure($onScheduleFailure('schedules:generate'));
Schedule::command('schedules:mark-missed')->dailyAt('00:17')->timezone(config('app.display_timezone'))->withoutOverlapping()
    ->onFailure($onScheduleFailure('schedules:mark-missed'));
Schedule::command('days:close')->dailyAt('00:23')->timezone(config('app.display_timezone'))->withoutOverlapping()
    ->onFailure($onScheduleFailure('days:close'));
Schedule::command('photos:prune-temp')->dailyAt('03:27')->timezone(config('app.display_timezone'))->withoutOverlapping()
    ->onFailure($onScheduleFailure('photos:prune-temp'));
