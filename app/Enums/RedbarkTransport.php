<?php

declare(strict_types=1);

namespace App\Enums;

enum RedbarkTransport: string
{
    case Rest = 'rest';
    case SimpleFin = 'simplefin';
}
