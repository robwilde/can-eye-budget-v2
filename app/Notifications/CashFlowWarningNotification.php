<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Casts\MoneyCast;
use App\Support\Bills\BillsLink;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Cash in the user's tracked everyday and savings accounts is projected to dip below their threshold within thirty
 * days. High priority when the low point is below zero.
 */
final class CashFlowWarningNotification extends Notification
{
    public function __construct(
        public readonly int $lowPoint,
        public readonly CarbonImmutable $lowOn,
        public readonly int $threshold,
    ) {}

    public function isHighPriority(): bool
    {
        return $this->lowPoint < 0;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = new MailMessage()
            ->subject($this->isHighPriority() ? 'Your cash may run out within 30 days' : 'Your cash may run low within 30 days')
            ->line(sprintf('After your open bills and typical spending, cash in your tracked accounts is projected to reach %s on %s.', MoneyCast::format($this->lowPoint), $this->lowOn->format('j M Y')))
            ->line(sprintf('Your alert threshold is %s.', MoneyCast::format($this->threshold)))
            ->action('Open your bills', BillsLink::for($notifiable));

        return $this->isHighPriority() ? $message->priority(1) : $message;
    }
}
