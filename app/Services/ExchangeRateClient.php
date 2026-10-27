<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ExchangeRateProviderContract;
use App\DTOs\ExchangeRateQuote;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final readonly class ExchangeRateClient implements ExchangeRateProviderContract
{
    private const int TIMEOUT_SECONDS = 15;

    public function __construct(
        private bool $enabled,
        private string $baseUrl,
        private string $apiKey = '',
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Returns null when the provider is disabled, fails, or has no usable rate for the pair. The
     * quote carries the date the provider quoted, which is earlier than $date for a weekend or
     * holiday; the lookup decides whether that date is recent enough to use.
     */
    public function rate(string $from, string $to, CarbonInterface $date): ?ExchangeRateQuote
    {
        $quote = $this->quote($from, [$to], $date);

        if ($quote === null) {
            return null;
        }

        $rate = $quote['rates'][mb_strtoupper($to)] ?? null;

        return $rate === null ? null : new ExchangeRateQuote($rate, CarbonImmutable::parse($quote['date']));
    }

    /**
     * Usable rates from $base into each of $symbols, with the date the provider quoted them for.
     * A provider answers with its last business day for a weekend or holiday, so the date can
     * be earlier than $date; it is never later. A quote dated later than $date, or one without
     * a valid calendar date, is taken to be for $date.
     *
     * Makes no request when the provider is disabled or there are no symbols, and then quotes
     * no rates. Returns null when the provider fails: a transport error or non-2xx response,
     * logged without the payload. Values that are not finite and above zero are skipped and
     * logged by currency code only.
     *
     * @param  list<string>  $symbols
     * @return array{date: string, rates: array<string, float>}|null
     */
    public function quote(string $base, array $symbols, CarbonInterface $date): ?array
    {
        $base = mb_strtoupper($base);
        $symbols = array_values(array_unique(array_map(strtoupper(...), $symbols)));
        $requested = $date->toDateString();

        if (! $this->enabled || $symbols === []) {
            return ['date' => $requested, 'rates' => []];
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->when($this->apiKey !== '', fn ($request) => $request->withHeaders(['X-Api-Key' => $this->apiKey]))
                ->get('/'.$requested, ['base' => $base, 'symbols' => implode(',', $symbols)]);
        } catch (ConnectionException) {
            Log::warning('Exchange rate provider unreachable', ['base' => $base]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Exchange rate provider request failed', ['base' => $base, 'status' => $response->status()]);

            return null;
        }

        $quotedFor = $response->json('date');
        $effective = is_string($quotedFor) && $this->isCalendarDate($quotedFor) && $quotedFor < $requested
            ? $quotedFor
            : $requested;
        $payload = $response->json('rates');
        $rates = [];

        foreach (is_array($payload) ? $payload : [] as $code => $value) {
            $code = mb_strtoupper((string) $code);

            if (! in_array($code, $symbols, true)) {
                continue;
            }

            if (! is_numeric($value) || ! ExchangeRate::isUsableRate((float) $value)) {
                Log::warning('Exchange rate provider returned an unusable rate', ['base' => $base, 'to' => $code]);

                continue;
            }

            $rates[$code] = (float) $value;
        }

        return ['date' => $effective, 'rates' => $rates];
    }

    private function isCalendarDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
