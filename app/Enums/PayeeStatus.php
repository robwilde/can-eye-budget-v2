<?php

declare(strict_types=1);

namespace App\Enums;

enum PayeeStatus: string
{
    /** Awaiting the user's answer, with or without a Jev suggestion. */
    case Pending = 'pending';

    /** The user chose the category; its transactions are Manual from then on. */
    case Confirmed = 'confirmed';

    /** The user skipped it; it is not offered for review again. */
    case Dismissed = 'dismissed';
}
