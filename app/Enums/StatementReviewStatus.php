<?php

declare(strict_types=1);

namespace App\Enums;

enum StatementReviewStatus: string
{
    case Unmatched = 'unmatched';
    case Linked = 'linked';
    case Rejected = 'rejected';
}
