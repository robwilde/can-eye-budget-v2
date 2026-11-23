<?php

declare(strict_types=1);

namespace App\Enums;

enum PlannedTransactionType: string
{
    case Bill = 'bill';
    case Subscription = 'subscription';
    case Installment = 'installment';
    case Income = 'income';
    case Transfer = 'transfer';
    case Other = 'other';

    /**
     * Types a plan of this direction may take. Transfer is never offered: it follows a destination account.
     *
     * @return list<self>
     */
    public static function selectableFor(TransactionDirection $direction): array
    {
        return $direction === TransactionDirection::Credit
            ? [self::Income, self::Other]
            : [self::Bill, self::Subscription, self::Installment, self::Other];
    }

    /**
     * The type to save: transfer when there is a destination, the chosen type when it suits the
     * direction, otherwise the direction's default (income for a credit, bill for a debit).
     */
    public static function resolve(?self $chosen, TransactionDirection $direction, bool $isTransfer): self
    {
        if ($isTransfer) {
            return self::Transfer;
        }

        $selectable = self::selectableFor($direction);

        return $chosen !== null && in_array($chosen, $selectable, true) ? $chosen : $selectable[0];
    }

    public function label(): string
    {
        return match ($this) {
            self::Bill => __('Bill'),
            self::Subscription => __('Subscription'),
            self::Installment => __('Installment'),
            self::Income => __('Income'),
            self::Transfer => __('Transfer'),
            self::Other => __('Other'),
        };
    }
}
