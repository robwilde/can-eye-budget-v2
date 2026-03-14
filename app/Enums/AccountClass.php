<?php

namespace App\Enums;

enum AccountClass: string
{
    case Transaction = 'transaction';
    case Savings = 'savings';
    case CreditCard = 'credit-card';
    case Loan = 'loan';
    case Mortgage = 'mortgage';
    case Investment = 'investment';
    case Insurance = 'insurance';
    case Foreign = 'foreign';
    case TermDeposit = 'term-deposit';
}
