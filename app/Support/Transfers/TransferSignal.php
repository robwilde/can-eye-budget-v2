<?php

declare(strict_types=1);

namespace App\Support\Transfers;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;

/**
 * What makes a row worth *considering* as a transfer leg. The signal alone never makes
 * a row a transfer: detection still needs a single mutual match, and the user confirms.
 */
final class TransferSignal
{
    public const string KEYWORD = 'transfer';

    /**
     * SQL pre-filter for isCandidate(): loads only rows that can possibly carry the signal, so a
     * sync never hydrates a user's whole history. isCandidate() stays the authority in memory.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public static function whereCandidate(Builder $query): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q
            ->where('description', 'like', '%'.self::KEYWORD.'%')
            ->orWhereHas('category', fn (Builder $c): Builder => $c
                ->where('name', 'Transfer')
                ->orWhereHas('parent', fn (Builder $p): Builder => $p->where('name', 'Transfer'))));
    }

    /** The description mentions a transfer, or the row is already categorised under Transfer. */
    public static function isCandidate(Transaction $transaction): bool
    {
        return mb_stripos($transaction->description, self::KEYWORD) !== false
            || self::hasTransferCategory($transaction);
    }

    /** Mirrors Transaction::scopeExcludingTransfers: the "Transfer" category or a direct child. */
    public static function hasTransferCategory(Transaction $transaction): bool
    {
        $category = $transaction->category;

        return $category instanceof Category
            && ($category->name === 'Transfer' || $category->parent?->name === 'Transfer');
    }
}
