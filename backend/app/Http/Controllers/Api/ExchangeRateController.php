<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ExchangeRateController extends Controller
{
    /**
     * GET /api/exchange-rates[?country=HU]
     * Latest euro rates plus the local currency of the user's country (or of ?country=).
     */
    public function index(Request $request, ExchangeRateService $fx): JsonResponse
    {
        $request->validate(['country' => ['nullable', 'string', 'size:2']]);
        $latest = $fx->latest();
        $local = $fx->currencyForCountry($request->input('country') ?? $request->user()->country);

        return response()->json([
            'base' => $fx->base(),
            'date' => $latest['date'],
            'source' => config('currency.source'),
            'rates' => (object) $latest['rates'],
            'local_currency' => $local,
            'local_rate' => $local ? ($latest['rates'][$local] ?? null) : null,
        ]);
    }

    /** POST /api/admin/exchange-rates/refresh  { date? } */
    public function refresh(Request $request, ExchangeRateService $fx): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:1999-01-04']]);
        try {
            $date = $fx->refresh($data['date'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
        return response()->json(['message' => "Exchange rates stored for {$date}.", 'date' => $date]);
    }
}
