<?php

declare(strict_types=1);

namespace App\Enums;

enum ImportPreset: string
{
    case BankCsv = 'bank_csv';
    case Mint = 'mint';
    case Ynab = 'ynab';
    case ActualBudget = 'actual_budget';
    case Qif = 'qif';

    public function label(): string
    {
        return match ($this) {
            self::BankCsv => 'Bank CSV',
            self::Mint => 'Mint export',
            self::Ynab => 'YNAB export',
            self::ActualBudget => 'Actual Budget export',
            self::Qif => 'QIF file (Quicken)',
        };
    }

    public function isQif(): bool
    {
        return $this === self::Qif;
    }

    public function usesBankMapping(): bool
    {
        return $this === self::BankCsv;
    }
}
