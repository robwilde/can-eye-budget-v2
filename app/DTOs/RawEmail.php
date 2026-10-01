<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Services\GmailService;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

/**
 * One mailbox message with its raw bodies, before any rendering. The message
 * id is trimmed of its angle brackets, as GmailService stores it.
 */
final class RawEmail extends Dto
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $subject,
        public readonly ?string $fromName,
        public readonly string $fromAddress,
        public readonly ?CarbonImmutable $date,
        public readonly ?string $textBody,
        public readonly ?string $htmlBody,
    ) {}

    /**
     * The rendered form the "link email" flow stores on a transaction.
     *
     * @param  array<string, mixed>|null  $details
     */
    public function toSearchResult(?array $details): EmailSearchResult
    {
        return new EmailSearchResult(
            messageId: $this->messageId,
            subject: $this->subject,
            fromName: $this->fromName,
            fromAddress: $this->fromAddress,
            date: $this->date?->toIso8601String(),
            snippet: GmailService::snippetFromBodies($this->textBody, $this->htmlBody),
            gmailUrl: GmailService::deepLink($this->messageId),
            details: $details,
        );
    }
}
