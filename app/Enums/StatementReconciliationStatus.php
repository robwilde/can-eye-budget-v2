<?php

declare(strict_types=1);

namespace App\Enums;

enum StatementReconciliationStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
