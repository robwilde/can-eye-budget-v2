<?php

declare(strict_types=1);

namespace App\Enums;

enum AuditOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';
}
