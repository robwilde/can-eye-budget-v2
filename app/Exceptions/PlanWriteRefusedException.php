<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\PlanRefusal;
use RuntimeException;

final class PlanWriteRefusedException extends RuntimeException
{
    public function __construct(public readonly PlanRefusal $reason)
    {
        parent::__construct($reason->message());
    }
}
