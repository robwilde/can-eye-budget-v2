<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Closed = 'closed';
}
