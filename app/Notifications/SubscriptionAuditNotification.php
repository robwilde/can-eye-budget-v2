<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Casts\MoneyCast;
use App\Support\Bills\BillsLink;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A bill that has gone unpaid for a whole cycle of its own: the user may have stopped using it, or missed a payment.
 */
final class SubscriptionAuditNotification extends Notification
{
    public function __construct(
        public readonly int $planId,
        public readonly string $name,
        public readonly int $amount,
        public readonly CarbonImmutable $dueOn,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage()
            ->subject(sprintf('%s has not been paid for a whole cycle', $this->name))
            ->line(sprintf('%s (%s) was due on %s and has not been paid for a whole cycle.', $this->name, MoneyCast::format($this->amount), $this->dueOn->format('j M Y')))
            ->line('If you no longer use it, you can pause or delete it; if you paid it another way, link the payment.')
            ->action('Open your bills', BillsLink::for($notifiable));
    }
}
