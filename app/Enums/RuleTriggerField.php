<?php

declare(strict_types=1);

namespace App\Enums;

enum RuleTriggerField: string
{
    case Description = 'description';
    case CleanDescription = 'clean_description';
    case MerchantName = 'merchant_name';
    case Amount = 'amount';
    case Direction = 'direction';
    case AccountId = 'account_id';
    case CategoryId = 'category_id';
    case Source = 'source';
    case AnzsicCode = 'anzsic_code';
    case Notes = 'notes';

    public function label(): string
    {
        return match ($this) {
            self::Description => 'Description',
            self::CleanDescription => 'Clean Description',
            self::MerchantName => 'Merchant Name',
            self::Amount => 'Amount',
            self::Direction => 'Direction',
            self::AccountId => 'Account',
            self::CategoryId => 'Category',
            self::Source => 'Source',
            self::AnzsicCode => 'ANZSIC Code',
            self::Notes => 'Notes',
        };
    }
}
