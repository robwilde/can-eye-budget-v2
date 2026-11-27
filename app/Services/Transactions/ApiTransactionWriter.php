<?php

declare(strict_types=1);

namespace App\Services\Transactions;

use App\Enums\CleanDescriptionSource;
use App\Enums\ExclusionSource;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Exceptions\TransactionWriteRefusedException;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\TransactionIngestor;
use App\Services\Transfers\TransferLinker;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates, edits and deletes the token owner's transactions for the REST API through the same funnel
 * the web forms use: new rows go through TransactionIngestor, edits are new versions (createChild)
 * under the account lock, and deletes go through TransactionDeleter. Bank-feed rows keep their
 * amount, date and direction; only a manual, unlinked, unsplit row can change those.
 */
final readonly class ApiTransactionWriter
{
    private const array AMOUNT_FIELDS = ['amount', 'direction', 'post_date', 'description'];

    public function __construct(
        private TransactionIngestor $ingestor,
        private AccountBalanceHistoryBuilder $history,
        private TransferLinker $transfers,
        private TransactionDeleter $deleter,
    ) {}

    /**
     * @param  array{account_id: int, amount: int, direction: string, post_date: string, description: string, category_id?: int|null, notes?: string|null, excluded?: bool}  $data
     *
     * @throws ModelNotFoundException
     * @throws Throwable
     */
    public function create(User $user, array $data): Transaction
    {
        $account = Account::query()->where('user_id', $user->id)->findOrFail($data['account_id']);
        $excluded = (bool) ($data['excluded'] ?? false);

        $transaction = new Transaction([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $data['category_id'] ?? null,
            'amount' => $data['amount'],
            'direction' => TransactionDirection::from($data['direction']),
            'description' => $data['description'],
            'post_date' => $data['post_date'],
            'status' => TransactionStatus::Posted,
            'source' => TransactionSource::Manual,
            'notes' => $data['notes'] ?? null,
            'excluded' => $excluded,
            'excluded_source' => $excluded ? ExclusionSource::Manual : null,
            'currency' => $account->currency,
        ]);

        return $this->ingestor->ingest($transaction);
    }

    /**
     * @param  array<string, mixed>  $changes  any of category_id, notes, excluded, and for a manual row amount, direction, post_date, description
     *
     * @throws TransactionWriteRefusedException
     * @throws ModelNotFoundException
     * @throws Throwable
     */
    public function update(User $user, int $transactionId, array $changes): Transaction
    {
        $row = Transaction::query()->where('user_id', $user->id)->current()->findOrFail($transactionId);

        $wantsAmountChange = array_intersect(array_keys($changes), self::AMOUNT_FIELDS) !== [];

        if ($wantsAmountChange) {
            $this->assertCoreEditable($row);
        }

        return DB::transaction(function () use ($user, $row, $changes, $wantsAmountChange): Transaction {
            $this->history->lockAccounts($row->account_id);

            $current = Transaction::lockCurrentVersion($row->id, $user->id);

            if ($wantsAmountChange) {
                $this->assertCoreEditable($current);
            }

            if (array_key_exists('excluded', $changes) && (bool) $changes['excluded'] === $current->excluded) {
                unset($changes['excluded']);

                if ($changes === []) {
                    return $current;
                }
            }

            $overrides = [];

            foreach (['category_id', 'notes', 'amount', 'description', 'post_date'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $overrides[$field] = $changes[$field];
                }
            }

            if (array_key_exists('direction', $changes)) {
                $overrides['direction'] = TransactionDirection::from((string) $changes['direction']);
            }

            if (array_key_exists('excluded', $changes)) {
                $excluded = (bool) $changes['excluded'];

                if (! $excluded && $current->isSplitParent()) {
                    throw new TransactionWriteRefusedException('A split transaction stays excluded until the split is undone.');
                }

                if ($excluded && $current->transfer_pair_id !== null) {
                    throw new TransactionWriteRefusedException('A transfer leg cannot be excluded.');
                }

                $overrides['excluded'] = $excluded;
                $overrides['excluded_source'] = ExclusionSource::Manual;
            }

            if (array_key_exists('description', $changes) && $changes['description'] !== '' && $current->cleanNameFollowsDescription()) {
                $overrides['clean_description'] = $changes['description'];
                $overrides['clean_description_source'] = CleanDescriptionSource::Manual->value;
            }

            $child = $current->createChild($overrides + ['locked_fields' => $current->lockedFieldsAfter($overrides) ?: null]);

            if ($wantsAmountChange) {
                $this->history->rebuildForEdit($current, $child);
            }

            $this->transfers->followVersion($current, $child);

            return $child;
        });
    }

    /**
     * @throws TransactionWriteRefusedException
     * @throws ModelNotFoundException
     * @throws Throwable
     */
    public function delete(User $user, int $transactionId): void
    {
        $row = Transaction::query()->where('user_id', $user->id)->current()->findOrFail($transactionId);

        $deleted = $this->deleter->delete(Transaction::query()->where('user_id', $user->id)->whereKey($row->id));

        if ($deleted === 0) {
            throw new TransactionWriteRefusedException('This transaction cannot be deleted: imported bank rows and split parts are kept.');
        }
    }

    /**
     * @throws TransactionWriteRefusedException
     */
    private function assertCoreEditable(Transaction $row): void
    {
        if ($row->source !== TransactionSource::Manual) {
            throw new TransactionWriteRefusedException('Only manually entered transactions can change amount, direction, date or description.');
        }

        if ($row->transfer_pair_id !== null || $row->suggested_pair_id !== null) {
            throw new TransactionWriteRefusedException('Transfers cannot be edited this way.');
        }

        if ($row->isSplitChild() || $row->isSplitParent() || $row->isSplit()) {
            throw new TransactionWriteRefusedException('Remove the split before changing the amount.');
        }
    }
}
