<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Casts\MoneyCast;
use App\Models\Transaction;
use App\Models\UserRule;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email a rule with the "Email digest" action sends: the new transactions that matched it.
 */
final class RuleMatchDigest extends Notification
{
    public const int LISTED = 25;

    /** @param  list<Transaction>  $transactions */
    public function __construct(public readonly UserRule $rule, public readonly array $transactions) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->transactions);

        $message = new MailMessage()
            ->subject(sprintf('%s: %d new %s', $this->rule->name, $count, $count === 1 ? 'transaction' : 'transactions'))
            ->line(sprintf('Your rule "%s" matched %d new %s.', $this->rule->name, $count, $count === 1 ? 'transaction' : 'transactions'));

        foreach (array_slice($this->transactions, 0, self::LISTED) as $transaction) {
            $message->line(sprintf(
                '%s — %s — %s%s',
                $transaction->post_date->format('j M Y'),
                $transaction->clean_description ?? $transaction->description,
                MoneyCast::format(abs($transaction->amount)),
                $transaction->account === null ? '' : ' ('.$transaction->account->name.')',
            ));
        }

        if ($count > self::LISTED) {
            $message->line(sprintf('…and %d more.', $count - self::LISTED));
        }

        return $message->action('Open your rules', route('rules'));
    }
}
