<?php

declare(strict_types=1);

namespace App\Enums;

enum GoalPledgeStatus: string
{
    case Open = 'open';
    case Matched = 'matched';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
