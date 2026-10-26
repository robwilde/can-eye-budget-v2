<?php

declare(strict_types=1);

use App\Models\Transaction;
use Illuminate\Database\Migrations\Migration;

/**
 * Versions edited before locked_fields existed were never touched by the bank sync, because it
 * wrote to the original row. The sync now writes to the current version, so each such version
 * gets the fields the user changed recorded as locked; otherwise the next sync would overwrite them.
 *
 * The original row is not the version's starting point: the old sync kept writing the bank's
 * description, amount, date and status to it. So only what a user can edit on a bank row is
 * compared against it, and the amount is locked only where a fee was folded into the chain. The
 * display name counts only when its source says the user wrote or cleared it: the old sync also
 * rewrote derived names on the original.
 * Query builder only: no model events, one user's chain at a time, in chunks.
 */
return new class extends Migration
{
    private const array COMPARED = [
        'clean_description',
        'notes',
        'category_id',
    ];

    public function up(): void
    {
        Transaction::query()->getConnection()->table('transactions')
            ->where('source', 'redbark')
            ->whereNotNull('parent_transaction_id')
            ->whereNull('locked_fields')
            ->whereNull('deleted_at')
            ->whereNotExists(fn ($children) => $children
                ->selectRaw('1')
                ->from('transactions as child')
                ->whereColumn('child.parent_transaction_id', 'transactions.id')
                ->whereNull('child.deleted_at'))
            ->chunkById(500, function ($versions): void {
                foreach ($versions as $version) {
                    [$original, $chain] = $this->chainOf($version);

                    if ($original === null) {
                        continue;
                    }

                    $changed = array_values(array_filter(
                        self::COMPARED,
                        fn (string $field): bool => (string) $original->{$field} !== (string) $version->{$field}
                            && ($field !== 'clean_description' || $this->nameWasEdited($version)),
                    ));

                    if ($this->foldedFeeInto($chain, $version->user_id)) {
                        $changed[] = 'amount';
                    }

                    if ($changed !== []) {
                        Transaction::query()->getConnection()->table('transactions')
                            ->where('id', $version->id)
                            ->where('user_id', $version->user_id)
                            ->update(['locked_fields' => json_encode($changed, JSON_THROW_ON_ERROR)]);
                    }
                }
            });
    }

    public function down(): void {}

    private function nameWasEdited(object $version): bool
    {
        return $version->clean_description === null || $version->clean_description_source === 'manual';
    }

    /**
     * @return array{0: object|null, 1: list<int>} the bank-keyed original and every id up to the version
     */
    private function chainOf(object $version): array
    {
        $row = $version;
        $chain = [$version->id];

        while ($row->parent_transaction_id !== null) {
            $row = Transaction::query()->getConnection()->table('transactions')
                ->where('id', $row->parent_transaction_id)
                ->where('user_id', $version->user_id)
                ->first();

            if ($row === null) {
                return [null, $chain];
            }

            $chain[] = $row->id;
        }

        return [$row->redbark_id !== null ? $row : null, $chain];
    }

    /**
     * @param  list<int>  $chain
     */
    private function foldedFeeInto(array $chain, int $userId): bool
    {
        return Transaction::query()->getConnection()->table('transactions')
            ->where('user_id', $userId)
            ->whereNotNull('deleted_at')
            ->whereIn('folded_into_transaction_id', $chain)
            ->exists();
    }
};
