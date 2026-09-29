<?php

declare(strict_types=1);

namespace App\Enums;

enum TransferLinkSource: string
{
    case Suggested = 'suggested';
    case Confirmed = 'confirmed';
    case Rule = 'rule';
    case Manual = 'manual';
    case Unlinked = 'unlinked';
}
