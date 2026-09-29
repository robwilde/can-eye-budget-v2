<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Enums\AccountClass;
use App\Enums\AccountGroup;
use App\Enums\AccountStatus;
use App\Enums\ImportSource;
use App\Models\Account;
use App\Models\User;

final class UntrackedAccountCreator
{
    /**
     * Untracked ("hidden") accounts carry a manually entered balance that linked transfers never change.
     */
    public function create(
        User $user,
        string $name,
        AccountClass $type = AccountClass::Savings,
        int $balanceCents = 0,
        ?string $description = null,
    ): Account {
        return Account::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'type' => $type,
            'group' => AccountGroup::LongTermSavings,
            'import_source' => ImportSource::Manual,
            'is_tracked' => false,
            'balance' => $balanceCents,
            'balance_updated_at' => now(),
            'description' => $description,
            'currency' => 'AUD',
            'status' => AccountStatus::Active,
        ]);
    }
}
