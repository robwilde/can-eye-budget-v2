<?php

declare(strict_types=1);

namespace App\Enums;

enum GoalHealth: string
{
    case Reached = 'reached';
    case NoTargetDate = 'no_target_date';
    case OnTrack = 'on_track';
    case Behind = 'behind';
    case Funded = 'funded';
    case Depleted = 'depleted';
}
