<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AccountClass;
use App\Models\Account;
use Spatie\LaravelData\Dto;

final class BalanceSheetGroup extends Dto
{
    /**
     * @param  list<Account>  $accounts  in the user's account-order preference
     * @param  int  $total  signed sum of the accounts' balances, in cents
     * @param  array<int, int>  $balances  each account's balance in cents by account id, in the base currency when budget.multi_currency is on
     */
    public function __construct(
        public readonly AccountClass $class,
        public readonly array $accounts,
        public readonly int $total,
        public readonly array $balances,
    ) {}
}
