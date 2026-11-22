<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the cash-flow Sankey and the outflows donut group spending and income: by category group
 * (the default) or by the account the money moved through.
 */
enum CashFlowGrouping: string
{
    case Group = 'group';
    case Account = 'account';

    public function label(): string
    {
        return match ($this) {
            self::Group => 'By category group',
            self::Account => 'By account',
        };
    }
}
