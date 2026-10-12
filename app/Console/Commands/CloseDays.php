<?php

namespace App\Console\Commands;

use App\Services\CashService;
use App\Services\SettingService;
use Illuminate\Console\Command;

class CloseDays extends Command
{
    /**
     * @var string
     */
    protected $signature = 'days:close';

    /**
     * @var string
     */
    protected $description = 'Close every open business day up to the day before yesterday (automatic daily closing)';

    public function handle(CashService $cash, SettingService $settings): int
    {
        if (! $settings->autoCloseDays()) {
            $this->info('Automatic day closing is off.');

            return self::SUCCESS;
        }

        $closed = $cash->closeDueDays();
        $this->info(sprintf('%d day(s) closed.', $closed));

        return self::SUCCESS;
    }
}
