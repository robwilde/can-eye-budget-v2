<?php

declare(strict_types=1);

namespace App\Exceptions\ContextDev;

use ContextDev\Core\Exceptions\ContextDevException;
use JsonException;

/**
 * Context.dev answered, but not with the JSON object its API returns.
 *
 * Typically an HTML error page from a gateway in front of the API. Extends the SDK's base
 * exception so callers keep a single catch for every Context.dev failure.
 */
final class ContextDevResponseException extends ContextDevException
{
    public static function notJson(JsonException $e): self
    {
        return new self('Context.dev returned a response that is not JSON.', previous: $e);
    }

    public static function notAnObject(): self
    {
        return new self('Context.dev brand response was not a JSON object.');
    }

    public static function missingCredits(): self
    {
        return new self('Context.dev response did not include key_metadata.credits_remaining.');
    }
}
