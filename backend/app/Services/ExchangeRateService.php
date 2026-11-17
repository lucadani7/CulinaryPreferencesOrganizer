<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Euro exchange rates (ECB reference rates via Frankfurter), stored per day in `exchange_rates`.
 * Prices are kept in euro; the rates are only used to show an amount in a local currency.
 */
class ExchangeRateService
{
    private const CACHE_KEY = 'fx:latest';

    public function base(): string
    {
        return config('currency.base');
    }

    /** Currency shown next to euro amounts for a country, or null for the euro area / unknown countries. */
    public function currencyForCountry(?string $country): ?string
    {
        return $country ? (config('currency.countries')[strtoupper($country)] ?? null) : null;
    }

    /**
     * The most recent day with stored rates: ['date' => 'Y-m-d'|null, 'rates' => ['RON' => 4.97, ...]].
     * Stale or missing rates are refreshed first; if the provider cannot be reached, the stored ones are used.
     */
    public function latest(): array
    {
        $this->refreshIfStale();

        return Cache::remember(self::CACHE_KEY, now()->addHour(), function () {
            $date = ExchangeRate::max('date');
            if (! $date) {
                return ['date' => null, 'rates' => []];
            }
            $date = Carbon::parse($date)->toDateString();

            return [
                'date' => $date,
                'rates' => ExchangeRate::whereDate('date', $date)->orderBy('currency')
                    ->pluck('rate', 'currency')->map(fn ($r) => (float) $r)->all(),
            ];
        });
    }

    /** Units of $currency for 1 EUR at the latest rate (1.0 for EUR, null when unknown). */
    public function rate(string $currency): ?float
    {
        $currency = strtoupper($currency);
        return $currency === $this->base() ? 1.0 : ($this->latest()['rates'][$currency] ?? null);
    }

    /** Converts a euro amount, or returns null when there is no rate for the currency. */
    public function fromEuro(float $amount, string $currency): ?float
    {
        $rate = $this->rate($currency);
        return $rate === null ? null : round($amount * $rate, 2);
    }

    /**
     * Downloads the rates of one day (latest by default) and stores them.
     * Returns the date the provider answered for (the last working day when $date is a weekend or holiday).
     */
    public function refresh(?string $date = null): string
    {
        $query = ['base' => strtolower($this->base())];
        if ($date) {
            $query['date'] = Carbon::parse($date)->toDateString();
        }

        try {
            $response = Http::timeout(config('currency.timeout'))->acceptJson()
                ->get(config('currency.rates_url'), $query);
        } catch (ConnectionException) {
            throw new RuntimeException('The exchange rate service cannot be reached right now.');
        }
        if ($response->failed()) {
            throw new RuntimeException('The exchange rate service answered with HTTP ' . $response->status() . '.');
        }

        $rows = $this->parse($response->json());
        if (! $rows) {
            throw new RuntimeException('The exchange rate service returned no rates.');
        }

        $now = now();
        DB::table('exchange_rates')->upsert(
            array_map(fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now], $rows),
            ['date', 'currency'],
            ['rate', 'updated_at'],
        );
        Cache::forget(self::CACHE_KEY);

        return max(array_column($rows, 'date'));
    }

    /** Refreshes at most once an hour when the newest stored rates are older than the configured age. */
    private function refreshIfStale(): void
    {
        if (! config('currency.auto_refresh')) {
            return;
        }
        $newest = ExchangeRate::max('updated_at');
        if ($newest && Carbon::parse($newest)->gt(now()->subHours(config('currency.max_age_hours')))) {
            return;
        }
        if (! Cache::add('fx:refresh-attempt', true, now()->addHour())) {
            return; // tried recently; do not slow down every request while the provider is down
        }
        try {
            $this->refresh();
        } catch (RuntimeException $e) {
            Log::warning('Exchange rates not refreshed: ' . $e->getMessage());
        }
    }

    /**
     * Frankfurter v2: [{"date":"2026-11-17","base":"EUR","quote":"RON","rate":5.0921}, ...]
     * (the v1 shape {"date":..., "rates": {"RON": ...}} is accepted too).
     *
     * @return list<array{date: string, currency: string, rate: float}>
     */
    private function parse(mixed $json): array
    {
        $rows = [];
        if (is_array($json) && isset($json['rates']) && is_array($json['rates'])) {
            foreach ($json['rates'] as $currency => $rate) {
                $rows[] = ['date' => $json['date'] ?? null, 'quote' => $currency, 'rate' => $rate];
            }
        } elseif (is_array($json) && array_is_list($json)) {
            $rows = $json;
        }

        $out = [];
        foreach ($rows as $r) {
            $currency = strtoupper((string) ($r['quote'] ?? ''));
            $rate = $r['rate'] ?? null;
            if (! preg_match('/^[A-Z]{3}$/', $currency) || ! is_numeric($rate) || $rate <= 0 || empty($r['date'])) {
                continue;
            }
            if ($currency === $this->base()) {
                continue;
            }
            $out[] = ['date' => Carbon::parse($r['date'])->toDateString(), 'currency' => $currency, 'rate' => (float) $rate];
        }
        return $out;
    }
}
