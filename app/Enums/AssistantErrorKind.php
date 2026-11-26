<?php

declare(strict_types=1);

namespace App\Enums;

enum AssistantErrorKind: string
{
    case RateLimited = 'rate_limited';
    case Unavailable = 'unavailable';
    case Misconfigured = 'misconfigured';
    case Generic = 'generic';
}
