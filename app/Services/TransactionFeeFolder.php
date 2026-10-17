<?php

declare(strict_types=1);

namespace App\Services;

use App\Casts\MoneyCast;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

final readonly class TransactionFeeFolder
{
    /**
     * Fold an international transaction fee into its matching parent purchase.
     *
     * Soft-deletes the fee (hiding it everywhere), links it to a merged
     * version-chain child of the parent that carries the combined amount, and
     * returns that merged child. Returns null (no side effects) when the fee is
     * not eligible or no unambiguous parent exists.
     */
    public function fold(Transaction $fee): ?Transaction
    {
        $parent = $this->foldableParent($fee);

        if ($parent === null) {
            return null;
        }

        return DB::transaction(function () use ($fee, $parent): Transaction {
            $note = sprintf('Includes intl transaction fee %s (folded)', MoneyCast::format($fee->amount));

            $merged = $parent->createChild([
                'amount' => $parent->amount + $fee->amount,
                'csv_hash' => null,
                'notes' => $parent->notes === null || $parent->notes === ''
                    ? $note
                    : $parent->notes."\n".$note,
            ]);

            $fee->folded_into_transaction_id = $merged->id;
            $fee->save();
            $fee->delete();

            return $merged;
        });
    }

    /**
     * The purchase fold() would merge this fee into, or null when the fee is
     * not eligible or no unambiguous parent exists. Read-only: fold() and the
     * rule tester share it so a dry run cannot disagree with the write.
     */
    public function foldableParent(Transaction $fee): ?Transaction
    {
        if ($fee->trashed()
            || $fee->folded_into_transaction_id !== null
            || $fee->planned_transaction_id !== null
            || $fee->transfer_pair_id !== null
        ) {
            return null;
        }

        return $this->findParent($fee);
    }

    /**
     * Locate the single current purchase this fee belongs to. Matching is on the
     * trailing five digits of the fee reference (the auth code that appears
     * before " #<card>" in the parent), same account/direction/post_date, with
     * the parent strictly larger in magnitude. Ambiguity or no match yields null.
     */
    public function findParent(Transaction $fee): ?Transaction
    {
        if (! preg_match('/(\d{5})\s*$/', $fee->description, $matches)) {
            return null;
        }

        $candidates = Transaction::query()
            ->where('user_id', $fee->user_id)
            ->where('account_id', $fee->account_id)
            ->where('direction', $fee->direction)
            ->whereDate('post_date', $fee->post_date->toDateString())
            ->whereKeyNot($fee->id)
            ->where('description', 'like', "%{$matches[1]} #%")
            ->whereRaw('ABS(amount) > ?', [abs($fee->amount)])
            ->current()
            ->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }
}
