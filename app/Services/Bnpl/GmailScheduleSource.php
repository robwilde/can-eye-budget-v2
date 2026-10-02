<?php

declare(strict_types=1);

namespace App\Services\Bnpl;

use App\Contracts\ScheduleSource;
use App\DTOs\RawEmail;
use App\Support\Email\GmailMailbox;
use Webklex\PHPIMAP\Message;

final readonly class GmailScheduleSource implements ScheduleSource
{
    public function __construct(private GmailMailbox $mailbox) {}

    public function fetch(string $query, int $limit): array
    {
        return $this->mailbox->search($query, $limit)
            ->map(static fn (Message $message): ?RawEmail => GmailMailbox::rawEmail($message))
            ->filter()
            ->values()
            ->all();
    }
}
