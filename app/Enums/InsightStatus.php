<?php

declare(strict_types=1);

namespace App\Enums;

enum InsightStatus: string
{
    case Active = 'active';
    case Read = 'read';
    case Acknowledged = 'acknowledged';
    case Expired = 'expired';
}
