<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class GmailSearchException extends RuntimeException
{
    public static function wrap(Throwable $e): self
    {
        return new self('Gmail search failed: '.$e->getMessage(), 0, $e);
    }

    public static function notConnected(): self
    {
        return new self('Gmail is not connected. Connect it under Settings > Providers.');
    }
}
