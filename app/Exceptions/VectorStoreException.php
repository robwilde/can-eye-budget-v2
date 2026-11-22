<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** The vector store refused or failed a call. Its message never holds a response body or the key. */
final class VectorStoreException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent)
    {
        parent::__construct($message);
    }
}
