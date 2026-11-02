<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Enums\BudgetTag;
use App\Models\Category;
use App\Models\User;

/**
 * The category a transfer fee is booked to: the user's own "Bank Fees" under "Bank Fees &
 * Finance Services", found by owner, parent and name. Categories are per user (ADR 0002), and
 * a bare "Fees" name would hit the card-specific child under Latitude. A user whose tree
 * lacks the pair gets it created, so a fee is never left uncategorised.
 */
final class TransferFeeCategory
{
    public const string PARENT = 'Bank Fees & Finance Services';

    public const string NAME = 'Bank Fees';

    /**
     * Call inside the write's DB transaction, after any account locks and before any insert:
     * the owner's row is locked so two first uses cannot both find nothing and each create the
     * pair. The lookups are locking reads, which see what a writer that held the owner's lock
     * committed even when this transaction's REPEATABLE READ snapshot was fixed before the wait.
     * Taking the owner lock before inserting matters on MariaDB: each transaction insert holds a
     * shared lock on the user row through its foreign key, and two writers that both hold it and
     * then ask for the exclusive lock deadlock.
     */
    public function forUser(int $userId): Category
    {
        User::query()->whereKey($userId)->lockForUpdate()->value('id');

        $parent = Category::query()
            ->where('user_id', $userId)
            ->whereNull('parent_id')
            ->where('name', self::PARENT)
            ->lockForUpdate()
            ->first() ?? Category::query()->create([
                'user_id' => $userId,
                'name' => self::PARENT,
                'icon' => 'building-library',
                'budget_tag' => BudgetTag::Needs,
            ]);

        return Category::query()
            ->where('user_id', $userId)
            ->where('parent_id', $parent->id)
            ->where('name', self::NAME)
            ->lockForUpdate()
            ->first() ?? Category::query()->create([
                'user_id' => $userId,
                'name' => self::NAME,
                'parent_id' => $parent->id,
            ]);
    }
}
