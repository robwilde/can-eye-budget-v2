<?php

declare(strict_types=1);

namespace App\Services\Investments;

use App\Contracts\SecurityPriceProviderContract;
use App\DTOs\SecurityPriceQuote;
use App\DTOs\SecuritySearchResult;
use App\Models\Security;
use App\Support\Investments\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Prices and ticker search from the Twelve Data API (GET {base_url}/time_series and /symbol_search).
 * The key goes in the Authorization header, never in the URL. A request gets 5 seconds to connect and
 * 10 to answer, and is tried twice on a transport error or a 5xx answer, so one call is bounded at about
 * 20 seconds. An error answer carries a body of status "error" and either HTTP 200 or the error's own
 * 4xx status; code 400 and 404 mean "no data" and every other code is a failure. The end date of a
 * time series is exclusive for the provider, so the request asks for the day after $to. Prices quoted
 * in a minor unit (GBp, ZAc) are not supported. Nothing is logged but the provider, the status and the
 * error code.
 */
final readonly class TwelveDataClient implements SecurityPriceProviderContract
{
    public const string KEY = 'twelvedata';

    private const int CONNECT_TIMEOUT_SECONDS = 5;

    private const int TIMEOUT_SECONDS = 10;

    private const int ATTEMPTS = 2;

    private const array MINOR_UNIT_CODES = ['GBX', 'ZAC', 'ILA'];

    private const int RETRY_DELAY_MS = 250;

    private const int SEARCH_LIMIT = 30;

    public function __construct(
        private bool $enabled,
        private string $baseUrl,
        private string $apiKey = '',
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function prices(string $ticker, string $exchangeMic, CarbonInterface $from, CarbonInterface $to): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        $query = array_filter([
            'symbol' => $ticker,
            'mic_code' => $exchangeMic === '' ? null : $exchangeMic,
            'interval' => '1day',
            'start_date' => $from->toDateString(),
            'end_date' => $to->addDay()->toDateString(),
            'order' => 'ASC',
            'outputsize' => 5000,
        ], static fn (mixed $value): bool => $value !== null);

        $body = $this->get('/time_series', $query);

        if ($body === null) {
            return null;
        }

        if (($body['status'] ?? null) === 'error') {
            return in_array((int) ($body['code'] ?? 0), [400, 404], true) ? [] : $this->failed((int) ($body['code'] ?? 0));
        }

        $currency = self::currencyOf($body['meta']['currency'] ?? null);
        $quotes = [];

        foreach (is_array($body['values'] ?? null) ? $body['values'] : [] as $row) {
            $quote = $this->quote($row, $currency);

            if ($quote !== null) {
                $quotes[] = $quote;
            }
        }

        usort($quotes, static fn (SecurityPriceQuote $a, SecurityPriceQuote $b): int => $a->date <=> $b->date);

        return $quotes;
    }

    public function search(string $query): array
    {
        if (! $this->enabled || mb_trim($query) === '') {
            return [];
        }

        $body = $this->get('/symbol_search', ['symbol' => mb_trim($query), 'outputsize' => self::SEARCH_LIMIT]);

        if ($body === null || ($body['status'] ?? null) === 'error') {
            return [];
        }

        $results = [];

        foreach (is_array($body['data'] ?? null) ? $body['data'] : [] as $row) {
            if (! is_array($row) || ! is_string($row['symbol'] ?? null) || ! is_string($row['instrument_name'] ?? null)) {
                continue;
            }

            $currency = self::currencyOf($row['currency'] ?? null);

            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                continue;
            }

            $results[] = new SecuritySearchResult(
                ticker: Security::normalise($row['symbol']),
                exchangeMic: Security::normalise(is_string($row['mic_code'] ?? null) ? $row['mic_code'] : ''),
                name: $row['instrument_name'],
                currency: $currency,
                provider: self::KEY,
            );
        }

        return $results;
    }

    /**
     * The ISO code of a quoted currency, or an empty string for one that is not a plain ISO code. A code
     * in mixed case (GBp pence, ZAc cents) or a known minor-unit code (GBX, ILA) marks a market quoted
     * in a minor unit, which a price here would read 100 times too high, so it is treated as
     * unsupported rather than guessed. An all-lower-case code is accepted as that code.
     */
    private static function currencyOf(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $code = mb_trim($value);

        if ($code === mb_strtolower($code)) {
            $code = mb_strtoupper($code);
        }

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1 || in_array($code, self::MINOR_UNIT_CODES, true)) {
            return '';
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null the decoded body, or null when the request failed
     */
    private function get(string $path, array $query): ?array
    {
        try {
            $response = $this->request()->get($path, $query);
        } catch (ConnectionException) {
            Log::warning('Security price provider unreachable', ['provider' => self::KEY]);

            return null;
        } catch (RequestException $e) {
            Log::warning('Security price provider request failed', ['provider' => self::KEY, 'status' => $e->response->status()]);

            return null;
        }

        return $this->decode($response);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::TIMEOUT_SECONDS)
            ->when($this->apiKey !== '', fn (PendingRequest $request) => $request->withHeaders(['Authorization' => 'apikey '.$this->apiKey]))
            ->retry(
                self::ATTEMPTS,
                self::RETRY_DELAY_MS,
                static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
                throw: false,
            );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(Response $response): ?array
    {
        $body = $response->json();
        $errorBody = $response->clientError() && is_array($body) && ($body['status'] ?? null) === 'error';

        if (! $response->successful() && ! $errorBody) {
            Log::warning('Security price provider request failed', ['provider' => self::KEY, 'status' => $response->status()]);

            return null;
        }

        return is_array($body) ? $body : null;
    }

    /**
     * @return null always: a provider error is a failure, never "no prices"
     */
    private function failed(int $code): null
    {
        Log::warning('Security price provider returned an error', ['provider' => self::KEY, 'code' => $code]);

        return null;
    }

    private function quote(mixed $row, string $currency): ?SecurityPriceQuote
    {
        if (! is_array($row) || ! is_string($row['datetime'] ?? null) || ! is_string($row['close'] ?? null)) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $row['datetime']) !== 1 || preg_match('/^\d+(\.\d+)?$/', $row['close']) !== 1 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return null;
        }

        try {
            $cents = Decimal::toCents(bcmul($row['close'], '100', 16));
        } catch (InvalidArgumentException) {
            return null;
        }

        return $cents > 0 ? new SecurityPriceQuote(CarbonImmutable::parse(mb_substr($row['datetime'], 0, 10)), $cents, $currency) : null;
    }
}
