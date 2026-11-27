<?php

declare(strict_types=1);

namespace App\Services\Transactions;

use App\Enums\CleanDescriptionSource;
use App\Enums\ExclusionSource;
use App\Enums\StaleVersion;
use App\Enums\TransactionDirection;
use App\Enums\TransactionKind;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Exceptions\TransactionWriteRefusedException;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\TransactionIdempotency;
use App\Services\TransactionIngestor;
use App\Services\Transfers\TransferLinker;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates, edits and deletes a user's transactions for the transaction form and list, the REST API and the
 * assistant, through the same funnel: new rows go through TransactionIngestor, an edit is a new version
 * (createChild) under the account lock, and a delete goes through TransactionDeleter.
 *
 * The rules live here once. Bank-feed rows never change their amount, date, direction or description, and no
 * row changes them while it is a transfer leg or split. A transfer leg cannot be excluded and a split parent
 * cannot be included. An exclusion is stamped manual only when it changes. TransactionWriteOptions says what
 * a caller may do beyond that: the form edits imported CSV and PDF rows and refuses a stale copy, the API
 * edits manual rows and follows the newest version.
 */
final readonly class TransactionWriter
{
    public const string SPLIT_PARENT_STAYS_EXCLUDED = 'A split transaction stays excluded until the split is undone.';

    public const string TRANSFER_LEG_NOT_EXCLUDABLE = 'A transfer leg cannot be excluded.';

    public const string STALE_VERSION = 'This transaction changed while you were editing it. Reopen it and try again.';

    private const array CORE_FIELDS = ['account_id', 'amount', 'direction', 'post_date', 'description'];

    private const array BALANCE_FIELDS = ['account_id', 'amount', 'direction', 'post_date', 'currency', 'exchange_rate'];

    public function __construct(
        private TransactionIngestor $ingestor,
        private TransactionIdempotency $idempotency,
        private AccountBalanceHistoryBuilder $history,
        private TransferLinker $transfers,
        private TransactionDeleter $deleter,
    ) {}

    /**
     * Why the row's exclusion may not be set to $excluded, or null (also when it already is).
     */
    public static function exclusionRefusal(Transaction $row, bool $excluded): ?string
    {
        return match (true) {
            $excluded === $row->excluded => null,
            ! $excluded && $row->isSplitParent() => self::SPLIT_PARENT_STAYS_EXCLUDED,
            $excluded && $row->transfer_pair_id !== null => self::TRANSFER_LEG_NOT_EXCLUDABLE,
            default => null,
        };
    }

    /**
     * @param  array{account_id: int, amount: int, direction: string, post_date: string, description: string, category_id?: int|null, merchant_id?: int|null, notes?: string|null, excluded?: bool, currency?: string|null, exchange_rate?: float|null}  $data
     *
     * @throws ModelNotFoundException
     * @throws Throwable
     */
    public function create(User $user, array $data, TransactionWriteOptions $options = new TransactionWriteOptions): Transaction
    {
        $account = Account::query()->where('user_id', $user->id)->findOrFail($data['account_id']);
        $excluded = (bool) ($data['excluded'] ?? false);
        $merchantId = $data['merchant_id'] ?? null;

        $attributes = [
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $data['category_id'] ?? null,
            'merchant_id' => $merchantId,
            'locked_fields' => $merchantId === null ? null : ['merchant'],
            'amount' => $data['amount'],
            'direction' => TransactionDirection::from($data['direction']),
            'description' => $data['description'],
            'post_date' => $data['post_date'],
            'status' => TransactionStatus::Posted,
            'source' => TransactionSource::Manual,
            'notes' => $data['notes'] ?? null,
            'excluded' => $excluded,
            'excluded_source' => $excluded ? ExclusionSource::Manual : null,
            'currency' => array_key_exists('currency', $data) ? $data['currency'] : $account->currency,
            'exchange_rate' => $data['exchange_rate'] ?? null,
        ];

        return DB::transaction(function () use ($attributes, $options): Transaction {
            // A new model each attempt: a retry after a deadlock must not reuse a row that was rolled back.
            $transaction = new Transaction($attributes);

            $this->history->lockAccounts($transaction->account_id);

            $created = $options->idempotencyKey === null
                ? $this->ingestor->ingest($transaction)
                : $this->idempotency->ingestOnce($transaction, $options->idempotencyKey);

            if ($options->within !== null) {
                ($options->within)($created);
            }

            return $created;
        }, $options->attempts);
    }

    /**
     * @param  array<string, mixed>  $changes  any of category_id, merchant_id, notes, excluded, clean_description, kind, currency, exchange_rate, and amount, direction, post_date, description, account_id
     *
     * @throws TransactionWriteRefusedException
     * @throws ModelNotFoundException
     * @throws Throwable
     */
    public function update(User $user, int $transactionId, array $changes, TransactionWriteOptions $options = new TransactionWriteOptions): Transaction
    {
        $query = Transaction::query()->where('user_id', $user->id);
        $row = $options->stale === StaleVersion::Follow ? $query->current()->findOrFail($transactionId) : $query->findOrFail($transactionId);

        $this->assertCoreEditable($row, $changes, $options);

        return DB::transaction(function () use ($user, $row, $changes, $options): Transaction {
            $this->history->lockAccounts($row->account_id, (int) ($changes['account_id'] ?? $row->account_id));

            $current = $this->lockedRow($user, $row, $options);

            if ($options->guard !== null) {
                ($options->guard)($current);
            }

            $this->assertCoreEditable($current, $changes, $options);

            if (array_key_exists('excluded', $changes) && (bool) $changes['excluded'] === $current->excluded) {
                unset($changes['excluded']);

                if ($changes === []) {
                    return $current;
                }
            }

            $overrides = $this->overrides($current, $changes);

            $child = $current->createChild($overrides + ['locked_fields' => $current->lockedFieldsAfter($overrides) ?: null]);

            if (array_intersect(self::BALANCE_FIELDS, array_keys($overrides)) !== [] && $this->changesBalance($current, $child)) {
                $this->history->rebuildForEdit($current, $child);
            }

            $current->source->isBankFeed()
                ? $this->transfers->followVersion($current, $child)
                : $this->transfers->followEditedVersion($current, $child);

            if ($options->within !== null) {
                ($options->within)($child);
            }

            return $child;
        }, $options->attempts);
    }

    /**
     * @throws TransactionWriteRefusedException
     * @throws ModelNotFoundException
     * @throws Throwable
     */
    public function delete(User $user, int $transactionId): void
    {
        $row = Transaction::query()->where('user_id', $user->id)->current()->findOrFail($transactionId);

        if ($row->isSplitChild()) {
            throw new TransactionWriteRefusedException('A split line is deleted with its parent. Undo the split or delete the parent.');
        }

        $deleted = $this->deleter->delete(Transaction::query()->where('user_id', $user->id)->whereKey($row->id));

        if ($deleted === 0) {
            throw new TransactionWriteRefusedException('This transaction cannot be deleted: imported bank rows and split parts are kept.');
        }
    }

    /**
     * The row to change, locked: the newest version when following, or the row the caller read when refusing a
     * stale copy (which then must still be live, with no newer version).
     *
     * @throws TransactionWriteRefusedException
     */
    private function lockedRow(User $user, Transaction $row, TransactionWriteOptions $options): Transaction
    {
        if ($options->stale === StaleVersion::Follow) {
            return Transaction::lockCurrentVersion($row->id, $user->id);
        }

        $locked = Transaction::withTrashed()->where('user_id', $user->id)->whereKey($row->id)->lockForUpdate()->first();

        if (! $locked instanceof Transaction || $locked->trashed() || $locked->children()->exists()) {
            throw new TransactionWriteRefusedException(self::STALE_VERSION);
        }

        return $locked;
    }

    /**
     * What the new version overrides: only the fields the caller named, with an exclusion stamped manual when it
     * changes, and a renamed description carrying the display name along when that name just copies it.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     *
     * @throws TransactionWriteRefusedException
     */
    private function overrides(Transaction $current, array $changes): array
    {
        $overrides = [];

        foreach (['category_id', 'merchant_id', 'notes', 'amount', 'description', 'post_date', 'account_id', 'currency', 'exchange_rate'] as $field) {
            if (array_key_exists($field, $changes)) {
                $overrides[$field] = $changes[$field];
            }
        }

        if (array_key_exists('direction', $changes)) {
            $overrides['direction'] = $this->direction($changes['direction']);
        }

        if (array_key_exists('kind', $changes)) {
            $overrides['kind'] = $changes['kind'] instanceof TransactionKind ? $changes['kind'] : TransactionKind::from((string) $changes['kind']);
        }

        if (array_key_exists('excluded', $changes)) {
            $excluded = (bool) $changes['excluded'];
            $refusal = self::exclusionRefusal($current, $excluded);

            if ($refusal !== null) {
                throw new TransactionWriteRefusedException($refusal);
            }

            $overrides['excluded'] = $excluded;
            $overrides['excluded_source'] = ExclusionSource::Manual;
        }

        if (array_key_exists('clean_description', $changes)) {
            $overrides['clean_description'] = $changes['clean_description'];

            if ($changes['clean_description'] !== null) {
                $overrides['clean_description_source'] = CleanDescriptionSource::Manual->value;
            }
        } elseif (array_key_exists('description', $changes) && $changes['description'] !== '' && $changes['description'] !== $current->description && $current->cleanNameFollowsDescription()) {
            $overrides['clean_description'] = $changes['description'];
            $overrides['clean_description_source'] = CleanDescriptionSource::Manual->value;
        }

        return $overrides;
    }

    /**
     * Whether the new version moves money differently from the one it replaces, so balance history is rebuilt.
     */
    private function changesBalance(Transaction $current, Transaction $child): bool
    {
        return $current->account_id !== $child->account_id
            || $current->amount !== $child->amount
            || $current->direction !== $child->direction
            || ! $current->post_date->isSameDay($child->post_date)
            || $current->currency !== $child->currency
            || (float) $current->exchange_rate !== (float) $child->exchange_rate;
    }

    /**
     * @param  array<string, mixed>  $changes
     *
     * @throws TransactionWriteRefusedException
     */
    private function assertCoreEditable(Transaction $row, array $changes, TransactionWriteOptions $options): void
    {
        $core = array_values(array_filter(
            self::CORE_FIELDS,
            fn (string $field): bool => array_key_exists($field, $changes) && $this->differs($row, $field, $changes[$field]),
        ));

        if ($core === []) {
            return;
        }

        if ($row->source->isBankFeed() || (! $options->allowImportedCoreEdits && $row->source !== TransactionSource::Manual)) {
            throw new TransactionWriteRefusedException('Only manually entered transactions can change amount, direction, date or description.');
        }

        if ($row->transfer_pair_id !== null || (! $options->allowImportedCoreEdits && $row->suggested_pair_id !== null)) {
            throw new TransactionWriteRefusedException('Transfers cannot be edited this way.');
        }

        $moves = array_diff($core, ['description']) !== [];
        $changesLines = array_diff($core, ['description', 'account_id']) !== [];
        $splitIntoTransactions = $row->isSplitChild() || $row->isSplitParent();

        if (($splitIntoTransactions && ($moves || ! $options->allowImportedCoreEdits)) || ($row->isSplit() && ($changesLines || ! $options->allowImportedCoreEdits))) {
            throw new TransactionWriteRefusedException('Remove the split before changing the amount.');
        }
    }

    private function differs(Transaction $row, string $field, mixed $value): bool
    {
        return match ($field) {
            'account_id' => (int) $value !== $row->account_id,
            'amount' => (int) $value !== $row->amount,
            'direction' => $this->direction($value) !== $row->direction,
            'post_date' => ($value instanceof CarbonInterface ? $value->toDateString() : (string) $value) !== $row->post_date->toDateString(),
            default => (string) $value !== (string) $row->description,
        };
    }

    private function direction(mixed $direction): TransactionDirection
    {
        return $direction instanceof TransactionDirection ? $direction : TransactionDirection::from((string) $direction);
    }
}
