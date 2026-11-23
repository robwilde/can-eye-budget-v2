<?php

declare(strict_types=1);

namespace App\Enums;

enum PlanAmountStrategy: string
{
    case Fixed = 'fixed';
    case Average = 'average';
    case LastPaid = 'last_paid';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => __('Fixed'),
            self::Average => __('Average of past payments'),
            self::LastPaid => __('Last paid'),
        };
    }
}
