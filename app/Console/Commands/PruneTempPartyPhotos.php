<?php

namespace App\Console\Commands;

use App\Services\PartyPhotoStore;
use Illuminate\Console\Command;

class PruneTempPartyPhotos extends Command
{
    /**
     * @var string
     */
    protected $signature = 'photos:prune-temp {--hours=24 : Delete uploads older than this}';

    /**
     * @var string
     */
    protected $description = 'Delete party photo uploads that were never attached to a saved form';

    public function handle(PartyPhotoStore $photos): int
    {
        $hours = (int) $this->option('hours');

        if ($hours < 1) {
            $this->error('--hours must be at least 1.');

            return self::INVALID;
        }

        $this->info(sprintf('%d temporary photo(s) deleted.', $photos->pruneTemporary($hours)));

        return self::SUCCESS;
    }
}
