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

    public function label(): string
    {
        return match ($this) {
            self::Buy => 'Buy',
            self::Sell => 'Sell',
            self::SweepIn => 'Sweep in',
            self::SweepOut => 'Sweep out',
            self::Reinvestment => 'Reinvestment',
            self::Dividend => 'Dividend',
            self::Interest => 'Interest',
            self::Fee => 'Fee',
            self::Deposit => 'Deposit',
            self::Withdrawal => 'Withdrawal',
        };
    }

    /** Activities that move shares in or out of a position and so carry a quantity and a price. */
    public function movesShares(): bool
    {
        return in_array($this, [self::Buy, self::Sell, self::SweepIn, self::SweepOut, self::Reinvestment], true);
    }

    /** Activities that take shares out of a position, stored with a negative quantity. */
    public function reducesPosition(): bool
    {
        return $this === self::Sell || $this === self::SweepOut;
    }

    /** Activities that need a real security; interest and fee fall back to a cash security. */
    public function needsSecurity(): bool
    {
        return $this->movesShares() || $this === self::Dividend;
    }
}
