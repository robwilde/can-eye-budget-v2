<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Http\Resources\Api\PayeeResource;
use App\Models\Payee;

final class ListPayees extends OwnedListTool
{
    public function name(): string
    {
        return 'list_payees';
    }

    public function description(): string
    {
        return 'Lists the user\'s payees (merchants).';
    }

    protected function model(): string
    {
        return Payee::class;
    }

    protected function resource(): string
    {
        return PayeeResource::class;
    }

    protected function order(): array
    {
        return ['merchant_name'];
    }
}
