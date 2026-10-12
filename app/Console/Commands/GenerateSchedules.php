<?php

namespace App\Console\Commands;

use App\Services\CollectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateSchedules extends Command
{
    /**
     * @var string
     */
    protected $signature = 'schedules:generate {--date= : Dhaka date Y-m-d (default: tomorrow)}';

    /**
     * @var string
     */
    protected $description = 'Create collection schedule rows due on a date from each account\'s collection plan';

    public function handle(CollectionService $collections): int
    {
        $option = $this->option('date');

        if (is_string($option) && Carbon::canBeCreatedFromFormat($option, '!Y-m-d') === false) {
            $this->error('--date must be Y-m-d.');

            return self::INVALID;
        }

        $date = is_string($option)
            ? Carbon::createFromFormat('!Y-m-d', $option)
            : Carbon::parse(today(config('app.display_timezone'))->addDay()->toDateString());

        $created = $collections->generateFor($date);
        $this->info(sprintf('%d schedule row(s) created for %s.', $created, $date->toDateString()));

        return self::SUCCESS;
    }
}
