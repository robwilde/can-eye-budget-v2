<?php

declare(strict_types=1);

namespace App\Enums;

enum OccurrenceStatus: string
{
    case Scheduled = 'scheduled';
    case Paid = 'paid';
    case Skipped = 'skipped';
    case Missed = 'missed';
}
