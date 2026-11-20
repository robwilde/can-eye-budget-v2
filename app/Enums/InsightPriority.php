<?php

declare(strict_types=1);

namespace App\Enums;

enum InsightPriority: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
