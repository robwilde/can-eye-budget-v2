<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A rule definition file that cannot be imported. Nothing from the file is saved.
 */
final class RuleImportException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct($errors[0] ?? 'The rule file could not be imported.');
    }
}
