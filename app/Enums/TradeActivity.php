<?php

declare(strict_types=1);

namespace App\Enums;

enum TradeActivity: string
{
    case Buy = 'buy';
    case Sell = 'sell';
    case SweepIn = 'sweep_in';
    case SweepOut = 'sweep_out';
    case Reinvestment = 'reinvestment';
    case Dividend = 'dividend';
    case Interest = 'interest';
    case Fee = 'fee';
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
}
