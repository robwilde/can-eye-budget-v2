<?php

declare(strict_types=1);

namespace App\Enums;

enum StatementDocumentType: string
{
    case BankStatement = 'bank_statement';
    case CreditCardStatement = 'credit_card_statement';
    case InvestmentStatement = 'investment_statement';
    case FinancialDocument = 'financial_document';
    case Contract = 'contract';
    case Other = 'other';

    /**
     * Only bank and credit-card statements list transactions to review.
     */
    public function producesLines(): bool
    {
        return $this === self::BankStatement || $this === self::CreditCardStatement;
    }
}
