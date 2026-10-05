<?php

declare(strict_types=1);

namespace App\Services\Bnpl;

use App\Contracts\ScheduleSource;
use App\DTOs\RawEmail;
use App\Models\User;
use App\Support\Email\GmailMailbox;
use RuntimeException;
use Webklex\PHPIMAP\Message;

final readonly class GmailScheduleSource implements ScheduleSource
{
    public function fetch(User $user, string $query, int $limit): array
    {
        $mailbox = GmailMailbox::forUser($user)
            ?? throw new RuntimeException("User {$user->id} has not connected Gmail.");

        return $mailbox->search($query, $limit)
            ->map(static fn (Message $message): ?RawEmail => GmailMailbox::rawEmail($message))
            ->filter()
            ->values()
            ->all();
    }
}
