<?php

namespace App\Console\Commands;

use App\Services\CollectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class MarkMissedSchedules extends Command
{
    /**
     * @var string
     */
    protected $signature = 'schedules:mark-missed {--date= : Dhaka date Y-m-d (default: yesterday)}';

    /**
     * @var string
     */
    protected $description = 'Flag pending collection rows due on or before a date as missed';

    public function handle(CollectionService $collections): int
    {
        $option = $this->option('date');

        if (is_string($option) && Carbon::canBeCreatedFromFormat($option, '!Y-m-d') === false) {
            $this->error('--date must be Y-m-d.');

            return self::INVALID;
        }

        $date = is_string($option)
            ? Carbon::createFromFormat('!Y-m-d', $option)
            : Carbon::parse(today(config('app.display_timezone'))->subDay()->toDateString());

        $marked = $collections->markMissed($date);
        $this->info(sprintf('%d schedule row(s) marked missed up to %s.', $marked, $date->toDateString()));

        return self::SUCCESS;
    }
}
