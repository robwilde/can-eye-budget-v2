<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a transaction write does when the row it was asked to change has a newer version by the time the
 * write holds its locks.
 */
enum StaleVersion: string
{
    /** Change the newest version (the API and the assistant: the caller asked for the transaction, not for one version). */
    case Follow = 'follow';

    /** Refuse (a form: the person edited an out-of-date copy, so saving it would overwrite what changed). */
    case Refuse = 'refuse';
}
