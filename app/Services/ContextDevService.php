<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ContextDevServiceContract;
use App\DTOs\MerchantBrandData;
use App\Exceptions\ContextDev\ContextDevResponseException;
use ContextDev\Client;
use ContextDev\Core\Exceptions\NotFoundException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Utils;
use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * Wraps the Context.dev PHP SDK. All Context.dev traffic goes through here.
 *
 * Retries are the SDK's: 408/409/429/5xx are retried up to RequestOptions::$maxRetries
 * (default 2); 400/401/403/404/422 never are. At the defaults there is effectively no
 * backoff: BaseClient::retryDelay() scales by retryCount² (0s before the first retry) and
 * sendRequest() truncates sub-second sleeps to zero, so both retries fire back-to-back.
 * A numeric Retry-After on any retried status is slept for as-is, not capped by
 * RequestOptions::$maxRetryDelay; the HTTP-date form resolves to zero.
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
     * The SDK also JSON-decodes every error body while building its status exception, so a
     * non-JSON error page (a gateway's HTML 404/502) would throw JsonException and lose the
     * status. The middleware re-wraps such bodies as JSON first, so every 4xx/5xx still maps
     * to its typed exception: 404 → NotFoundException, 5xx retried → InternalServerException.
     *
     * @param  callable|null  $handler  Guzzle handler stack; tests inject a MockHandler here.
     */
    public static function withApiKey(string $apiKey, ?callable $handler = null): self
    {
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::mapResponse(self::jsonErrorBody(...)), 'context_dev_json_error_body');

        $transporter = new GuzzleClient(['http_errors' => false, 'handler' => $stack]);

        return new self(new Client(apiKey: $apiKey, requestOptions: ['transporter' => $transporter]));
    }

    /**
     * Always sends high_confidence_only: a wrong merchant on a budget line is worse than an
     * unresolved one.
     *
     * Any 404 means unresolved, whatever its body. A 2xx whose body is not a JSON object is
     * a failure.
     *
     * @throws ContextDevResponseException when a 2xx response is not a JSON object
     */
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
            'high_confidence_only' => true,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        try {
            $response = $this->client->request(method: 'post', path: 'brand/retrieve', body: $body);
            $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (NotFoundException) {
            return null;
        } catch (JsonException $e) {
            throw ContextDevResponseException::notJson($e);
        }

        if (! is_array($payload)) {
            throw ContextDevResponseException::notAnObject();
        }

        return $this->toMerchant($payload);
    }

    private static function jsonErrorBody(ResponseInterface $response): ResponseInterface
    {
        $body = (string) $response->getBody();

        if ($response->getStatusCode() < 400 || json_validate($body)) {
            return $response;
        }

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withBody(Utils::streamFor(json_encode(['body' => mb_substr($body, 0, 500)], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)));
    }

    /**
     * Brand fields are independently optional: a domain alone still identifies the
     * merchant (and becomes the title), so only a brand with neither is unresolved.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function toMerchant(array $payload): ?MerchantBrandData
    {
        $brand = $payload['brand'] ?? null;

        if (! is_array($brand)) {
            return null;
        }

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
