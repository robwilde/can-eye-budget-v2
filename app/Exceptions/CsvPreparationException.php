<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Why a CSV could not be prepared. The import screen and the API each word it for their user.
 */
final class CsvPreparationException extends RuntimeException
{
    /**
     * @param  list<string>  $accounts  the accounts a file names when it holds more than one
     */
    private function __construct(public readonly bool $holdsSeveralAccounts, public readonly array $accounts = [])
    {
        parent::__construct($holdsSeveralAccounts ? 'The file holds more than one account.' : 'The file could not be read.');
    }

    public static function unreadable(): self
    {
        return new self(false);
    }

    /**
     * @param  list<string>  $accounts
     */
    public static function severalAccounts(array $accounts): self
    {
        return new self(true, $accounts);
    }
}
