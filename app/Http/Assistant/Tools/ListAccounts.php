<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Http\Resources\Api\AccountResource;
use App\Models\Account;

final class ListAccounts extends OwnedListTool
{
    public function name(): string
    {
        return 'list_accounts';
    }

    public function description(): string
    {
        return 'Lists the user\'s accounts with current balances in integer cents (100 = 1.00).';
    }

    protected function model(): string
    {
        return Account::class;
    }

    protected function resource(): string
    {
        return AccountResource::class;
    }

    protected function order(): array
    {
        return ['name'];
    }
}
