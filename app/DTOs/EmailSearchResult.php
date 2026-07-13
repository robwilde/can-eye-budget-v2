<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

final class EmailSearchResult extends Dto
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $subject,
        public readonly ?string $fromName,
        public readonly string $fromAddress,
        public readonly ?string $date,
        public readonly ?string $snippet,
        public readonly string $gmailUrl,
    ) {}

    /**
     * @return array{messageId: string, subject: string, fromName: ?string, fromAddress: string, date: ?string, snippet: ?string, gmailUrl: string}
     */
    public function toArray(): array
    {
        return [
            'messageId' => $this->messageId,
            'subject' => $this->subject,
            'fromName' => $this->fromName,
            'fromAddress' => $this->fromAddress,
            'date' => $this->date,
            'snippet' => $this->snippet,
            'gmailUrl' => $this->gmailUrl,
        ];
    }
}
