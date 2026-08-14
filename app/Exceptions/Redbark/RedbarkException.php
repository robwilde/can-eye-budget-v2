<?php

declare(strict_types=1);

namespace App\Exceptions\Redbark;

use RuntimeException;
use Throwable;

/**
 * Base for every Redbark client failure. Abstract because this repo makes every
 * concrete class final: throw RedbarkRequestException for the general case.
 *
 * $errorType is the machine-readable discriminator the sync job branches on — notably
 * `not_found` and `bad_request`, which are what tell it to retry a rejected batched
 * /balances call one account at a time.
 *
 * Types in use: bad_request, unauthorized, access_forbidden, not_found, rate_limited,
 * server_error, truncated, too_many_pages, unknown.
 */
abstract class RedbarkException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorType = 'unknown',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
