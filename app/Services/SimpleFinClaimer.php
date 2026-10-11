<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\Redbark\RedbarkException;
use App\Exceptions\Redbark\RedbarkRateLimitException;
use App\Exceptions\Redbark\RedbarkRequestException;
use App\Exceptions\Redbark\RedbarkServerException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * Exchanges a SimpleFIN setup token for an access URL.
 *
 * A setup token is the base64 of a claim URL, and claiming is one-shot: a retry after a
 * lost response would only be refused. The claim is therefore sent exactly once, and only
 * to the Redbark host, because the decoded URL is user-supplied and would otherwise let
 * anyone point this server at an arbitrary address.
 *
 * Failures that mean "this token is no good" carry errorType `invalid_token`; everything
 * else is a transient provider problem the user may retry with a fresh token.
 */
final readonly class SimpleFinClaimer
{
    public const int TIMEOUT_SECONDS = 15;

    public function __construct(private string $allowedHost) {}

    /**
     * @throws RedbarkException
     */
    public function claim(#[SensitiveParameter] string $setupToken): string
    {
        $claimUrl = $this->claimUrlFrom($setupToken);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withBody('', 'text/plain')
                ->post($claimUrl);
        } catch (ConnectionException $e) {
            throw new RedbarkRequestException('Connection failed while claiming', 'connection_failed', $e);
        }

        $status = $response->status();

        if ($status === 200) {
            return $this->accessUrlFrom($response->body());
        }

        throw match (true) {
            in_array($status, [400, 403, 404, 410], true) => new RedbarkRequestException("Setup token refused ($status)", 'invalid_token'),
            $status === 429 => new RedbarkRateLimitException('Rate limit exceeded', 'rate_limited'),
            $status >= 500 => new RedbarkServerException("Redbark server error ($status)", 'server_error'),
            default => new RedbarkRequestException("Unexpected claim response $status", 'unknown'),
        };
    }

    /**
     * @throws RedbarkRequestException
     */
    private function claimUrlFrom(#[SensitiveParameter] string $setupToken): string
    {
        $decoded = base64_decode(mb_trim($setupToken), true);

        if ($decoded === false || $decoded === '') {
            throw new RedbarkRequestException('Setup token is not valid base64', 'invalid_token');
        }

        $parts = parse_url($decoded);

        $valid = is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host'])
            && mb_strtolower($parts['host']) === mb_strtolower($this->allowedHost)
            && array_intersect_key($parts, array_flip(['user', 'pass', 'port', 'query', 'fragment'])) === []
            && preg_match('#^/simplefin/claim/[A-Za-z0-9_-]+$#', $parts['path'] ?? '') === 1;

        if (! $valid) {
            throw new RedbarkRequestException('Setup token does not point at the Redbark claim endpoint', 'invalid_token');
        }

        return $decoded;
    }

    /**
     * @throws RedbarkRequestException
     */
    private function accessUrlFrom(#[SensitiveParameter] string $body): string
    {
        $accessUrl = mb_trim($body);
        $parts = parse_url($accessUrl);

        $valid = is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host'], $parts['user'], $parts['pass'])
            && mb_strtolower($parts['host']) === mb_strtolower($this->allowedHost);

        if (! $valid) {
            throw new RedbarkRequestException('Claim response was not a Redbark access URL', 'unknown');
        }

        return $accessUrl;
    }
}
