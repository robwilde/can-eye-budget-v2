<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case Available = 'available';
    case Inactive = 'inactive';
    case Closed = 'closed';
}
