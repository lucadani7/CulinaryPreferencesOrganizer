<?php

namespace App\Console\Commands;

use App\Services\ExchangeRateService;
use Illuminate\Console\Command;
use RuntimeException;

class RefreshExchangeRates extends Command
{
    protected $signature = 'fx:refresh {--date= : Day to download (Y-m-d); the latest rates by default}';

    protected $description = 'Download the ECB euro reference rates (via Frankfurter) and store them';

    public function handle(ExchangeRateService $fx): int
    {
        try {
            $date = $fx->refresh($this->option('date') ?: null);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Exchange rates stored for {$date}.");
        return self::SUCCESS;
    }
}
