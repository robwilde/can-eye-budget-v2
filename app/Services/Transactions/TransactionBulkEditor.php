<?php

declare(strict_types=1);

namespace App\Services\Transactions;

use App\DTOs\BulkTransactionEdit;
use App\Enums\CleanDescriptionSource;
use App\Enums\TransactionSource;
use App\Models\Transaction;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\Tags\TransactionTagger;
use App\Services\Transfers\TransferLinker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Changes the date, name or notes of many transactions at once. Each row gets a new
 * version through createChild, exactly as an edit in the transaction modal does, and the
 * balance history is rebuilt in the same DB transaction as the writes.
 *
 * A split parent is skipped: its amount is divided by its split lines or by its child
 * transactions. A bank-feed row keeps the bank's date and description, so a date is not
 * applied to it and a name becomes its
 * display name, as in the modal. A date is not applied to a linked transfer leg either: the
 * modal moves both legs together, and one leg alone would leave the pair on different days.
 * A split child keeps its parent's date for the same reason.
 */
final readonly class TransactionBulkEditor
{
    public function __construct(
        private TransferLinker $transfers,
        private AccountBalanceHistoryBuilder $history,
        private TransactionTagger $tagger,
    ) {}

    /**
     * The rows of $query that $edit would change.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function editable(Builder $query, BulkTransactionEdit $edit): Builder
    {
        return $query
            ->unless($edit->changesOnlyTags(), fn (Builder $q): Builder => $q
                ->whereDoesntHave('splits')
                ->whereDoesntHave('splitChildren'))
            ->when($edit->changesOnlyDate(), fn (Builder $q): Builder => $q
                ->whereNull('transfer_pair_id')
                ->whereNull('split_parent_id')
                ->whereNotIn(
                    'source',
                    array_map(static fn (TransactionSource $source): string => $source->value, TransactionSource::bankFeed()),
                ));
    }

    /**
     * The rows of $editable that an edit including the date and another field changes but
     * leaves on their own date: a bank-feed row and a linked transfer leg.
     *
     * @param  Builder<Transaction>  $editable
     * @return Builder<Transaction>
     */
    public function dateKept(Builder $editable): Builder
    {
        return $editable->where(fn (Builder $q): Builder => $q
            ->whereNotNull('transfer_pair_id')
            ->orWhereNotNull('split_parent_id')
            ->orWhereIn(
                'source',
                array_map(static fn (TransactionSource $source): string => $source->value, TransactionSource::bankFeed()),
            ));
    }

    /**
     * Edits every editable row of $query, which the caller has already scoped to one user.
     *
     * @param  Builder<Transaction>  $query
     * @return int the number of rows edited
     *
     * @throws Throwable
     */
    public function edit(Builder $query, BulkTransactionEdit $edit): int
    {
        $editable = $this->editable($query, $edit);
        $accountIds = $this->history->accountIdsOfRows($editable);

        return DB::transaction(function () use ($editable, $edit, $accountIds): int {
            $this->history->lockAccounts(...$accountIds);

            $edited = [];
            $tagged = 0;
            $tagIds = [];

            foreach ((clone $editable)->orderBy('id')->lockForUpdate()->get() as $transaction) {
                $transaction->refresh();
                $overrides = $this->overrides($transaction, $edit);
                $rowTagIds = $edit->tagIds === null
                    ? null
                    : $tagIds[$transaction->user_id] ??= $this->tagger->resolve($transaction->user_id, $edit->tagIds);

                if ($overrides === []) {
                    if ($rowTagIds !== null) {
                        $this->tagger->apply($transaction, $rowTagIds);
                        $tagged++;
                    }

                    continue;
                }

                $child = $transaction->createChild($overrides + ['locked_fields' => $transaction->lockedFieldsAfter($overrides) ?: null]);

                $transaction->source->isBankFeed()
                    ? $this->transfers->followVersion($transaction, $child)
                    : $this->transfers->followEditedVersion($transaction, $child);

                if ($rowTagIds !== null) {
                    $this->tagger->apply($child->refresh(), $rowTagIds);
                }

                $edited[] = [$transaction, $child];
            }

            $this->history->rebuildForEdits(...$edited);

            return count($edited) + $tagged;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function overrides(Transaction $transaction, BulkTransactionEdit $edit): array
    {
        $isBankFeed = $transaction->source->isBankFeed();
        $overrides = [];

        if ($edit->date !== null && ! $isBankFeed && $transaction->transfer_pair_id === null && ! $transaction->isSplitChild()) {
            $overrides['post_date'] = $edit->date;
        }

        if ($edit->name !== null) {
            $overrides += $isBankFeed
                ? ['clean_description' => $edit->name, 'clean_description_source' => CleanDescriptionSource::Manual->value]
                : $this->renamed($transaction, $edit->name);
        }

        if ($edit->setNotes) {
            $overrides['notes'] = $edit->notes;
        }

        return $overrides;
    }

    /**
     * @return array<string, string>
     */
    private function renamed(Transaction $transaction, string $name): array
    {
        if (! $transaction->cleanNameFollowsDescription()) {
            return ['description' => $name];
        }

        return [
            'description' => $name,
            'clean_description' => $name,
            'clean_description_source' => CleanDescriptionSource::Manual->value,
        ];
    }
}
