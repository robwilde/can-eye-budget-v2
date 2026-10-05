<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\RawEmail;
use App\Models\User;
use Throwable;

/**
 * Where BNPL schedule emails come from. The Gmail mailbox in production; a
 * fake in tests (no IMAP in the suite).
 */
interface ScheduleSource
{
    /**
     * @param  string  $query  a ScheduleStrategy::query() value
     * @return list<RawEmail> in mailbox order (unspecified); callers sort
     *
     * @throws Throwable when the mailbox cannot be read
     */
    public function fetch(User $user, string $query, int $limit): array;
}
