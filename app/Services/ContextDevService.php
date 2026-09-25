<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ContextDevServiceContract;
use App\DTOs\MerchantBrandData;
use ContextDev\Client;
use ContextDev\Core\Exceptions\NotFoundException;
use GuzzleHttp\Client as GuzzleClient;
use UnexpectedValueException;

/**
 * Wraps the Context.dev PHP SDK. All Context.dev traffic goes through here.
 *
 * Retries are the SDK's: 408/409/429/5xx are retried up to RequestOptions::$maxRetries
 * (default 2) with exponential backoff capped at 8s, and a 429's Retry-After header is
 * honoured. 400/401/403/404/422 are never retried.
 *
 * Brand lookups go through Client::request() rather than $client->brand->retrieve():
 * the generated helper requires every lookup type's identifier at once, which cannot
 * express a by_transaction request (see docs.context.dev/sdks/php).
 */
final readonly class ContextDevService implements ContextDevServiceContract
{
    public function __construct(private Client $client) {}

    /**
     * Build the SDK client with a transport it can actually read errors from.
     *
     * Left to itself the SDK discovers a stock Guzzle client, whose http_errors=true makes
     * send() throw on every 4xx/5xx before the SDK sees the status. The SDK then reports
     * them all as APIConnectionException: 429/5xx are never retried and 404 never maps to
     * NotFoundException. http_errors=false hands the SDK the real response.
     *
     * @param  callable|null  $handler  Guzzle handler stack; tests inject a MockHandler here.
     */
    public static function withApiKey(string $apiKey, ?callable $handler = null): self
    {
        $transporter = new GuzzleClient(array_filter([
            'http_errors' => false,
            'handler' => $handler,
        ], static fn (mixed $value): bool => $value !== null));

        return new self(new Client(apiKey: $apiKey, requestOptions: ['transporter' => $transporter]));
    }

    public function brandFromTransaction(
        string $descriptor,
        ?string $countryCode = null,
        ?string $city = null,
        ?string $mcc = null,
    ): ?MerchantBrandData {
        $body = array_filter([
            'type' => 'by_transaction',
            'transaction_info' => $descriptor,
            'country_gl' => $countryCode,
            'city' => $city,
            'mcc' => $mcc,
            // A wrong merchant on a budget line is worse than an unresolved one.
            'high_confidence_only' => true,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        try {
            $response = $this->client->request(method: 'post', path: 'brand/retrieve', body: $body);
        } catch (NotFoundException) {
            return null;
        }

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new UnexpectedValueException('Context.dev brand response was not a JSON object.');
        }

        return $this->toMerchant($payload);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function toMerchant(array $payload): ?MerchantBrandData
    {
        $brand = $payload['brand'] ?? null;

        if (! is_array($brand)) {
            return null;
        }

        // Brand fields are independently optional: a domain alone still identifies the
        // merchant, so only a response with neither is unresolved.
        $domain = $this->string($brand['domain'] ?? null);
        $title = $this->string($brand['title'] ?? null) ?? $domain;

        if ($title === null) {
            return null;
        }

        $eic = $brand['industries']['eic'][0] ?? null;

        return new MerchantBrandData(
            title: $title,
            domain: $domain,
            logoUrl: $this->logoUrl($brand['logos'] ?? null),
            industry: is_array($eic) ? $this->string($eic['industry'] ?? null) : null,
            subindustry: is_array($eic) ? $this->string($eic['subindustry'] ?? null) : null,
            partial: ($payload['partial'] ?? false) === true,
        );
    }

    /**
     * Square icons suit a transaction row; fall back to the first logo of any shape.
     */
    private function logoUrl(mixed $logos): ?string
    {
        if (! is_array($logos)) {
            return null;
        }

        $fallback = null;

        foreach ($logos as $logo) {
            if (! is_array($logo) || ($url = $this->string($logo['url'] ?? null)) === null) {
                continue;
            }

            if (($logo['type'] ?? null) === 'icon') {
                return $url;
            }

            $fallback ??= $url;
        }

        return $fallback;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
