<?php

declare(strict_types=1);

namespace App\Enums;

enum ChatMessageStatus: string
{
    case Pending = 'pending';
    case Complete = 'complete';
    case Failed = 'failed';
    case Undelivered = 'undelivered';
}
