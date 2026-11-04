<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\DTOs\NewTransfer;
use App\Enums\CategorySource;
use App\Enums\TransactionDirection;
use App\Enums\TransactionKind;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransferLinkSource;
use App\Exceptions\AccountNotTrackedException;
use App\Exceptions\AccountNotUntrackedException;
use App\Exceptions\ExchangeRateUnavailableException;
use App\Exceptions\IdempotencyKeyReusedException;
use App\Exceptions\RealOppositeRowExistsException;
use App\Exceptions\TransferAlreadyLinkedException;
use App\Exceptions\TransferLegsTooFarApartException;
use App\Exceptions\TransferLinkRefusedException;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\TransferRule;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\TransactionIdempotency;
use App\Support\Transfers\TransferSignal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Writes transfer links for detection, rules, review and the modal's link-to-existing
 * flow. Linking sets transfer_pair_id (and transfer_link_source) on both legs under a
 * row lock and inherits the Transfer category; unlinking is non-destructive: it clears
 * both ids and stamps both legs Unlinked so detection and rules never re-link them.
 * (Manual-row transfers created in the modal write their own pair with source Manual.)
 */
final class TransferLinker
{
    public const int WINDOW_DAYS = 3;

    public const int MANUAL_WINDOW_DAYS = 30;

    public function __construct(
        private AccountBalanceHistoryBuilder $history,
        private TransactionIdempotency $idempotency,
        private TransferFeeCategory $feeCategory,
        private TransferExchange $exchange,
        private TransferKinds $kinds,
    ) {}

    /**
     * Unlinked rows on tracked accounts that could be the opposite leg of $transaction:
     * same user, opposite direction, same currency and absolute amount, within the date window,
     * different account, not already linked and not previously rejected. Suggestions use
     * the default window; a manual search passes MANUAL_WINDOW_DAYS. $lockOnAccountId narrows
     * the search to that account and reads it with a locking read in id order, so a write
     * recheck inside a transaction sees the latest commits. With $crossCurrency the rows in
     * another currency that are within the tolerance of the converted amount follow the exact ones.
     *
     * @return Collection<int, Transaction>
     */
    public function candidatesFor(Transaction $transaction, bool $includeRejected = false, int $windowDays = self::WINDOW_DAYS, ?int $lockOnAccountId = null, bool $crossCurrency = false, ?TransferExchange $exchange = null): Collection
    {
        $exact = $this->candidateQuery($transaction, $includeRejected, $windowDays, $lockOnAccountId)
            ->where('currency', $transaction->currency)
            ->whereRaw('ABS(amount) = ?', [abs($transaction->amount)])
            ->get();

        if (! $crossCurrency) {
            return $exact;
        }

        return $exact->merge($this->crossCurrencyCandidatesFor($transaction, $includeRejected, $windowDays, $lockOnAccountId, $exchange));
    }

    /**
     * Opposite-direction rows in another currency whose amount is within the configured
     * tolerance of $transaction's once converted. The search is the one candidatesFor() runs,
     * minus the exact amount; a pair with no known rate is never returned.
     *
     * @return Collection<int, Transaction>
     */
    public function crossCurrencyCandidatesFor(Transaction $transaction, bool $includeRejected = false, int $windowDays = self::WINDOW_DAYS, ?int $lockOnAccountId = null, ?TransferExchange $exchange = null): Collection
    {
        $exchange ??= $this->exchange->memoising();

        return $this->candidateQuery($transaction, $includeRejected, $windowDays, $lockOnAccountId)
            ->where('currency', '!=', $transaction->currency)
            ->get()
            ->filter(fn (Transaction $other): bool => $transaction->direction === TransactionDirection::Debit
                ? $exchange->qualifies($transaction, $other)
                : $exchange->qualifies($other, $transaction))
            ->values();
    }

    /**
     * Links two rows as a transfer. Both rows are re-read under a lock, in a stable order, so
     * concurrent detection and modal saves cannot leave a leg pointing at a row that was
     * paired with someone else.
     *
     * @return bool false when either row was linked to a different row in the meantime, or
     *              (for automatic Rule links) was rejected by the user; nothing is written then
     *
     * @throws TransferLegsTooFarApartException when the legs are more than MANUAL_WINDOW_DAYS apart
     * @throws Throwable
     */
    public function link(Transaction $a, Transaction $b, TransferLinkSource $source): bool
    {
        if ($a->user_id !== $b->user_id || $a->id === $b->id) {
            throw new InvalidArgumentException('Transfer legs must be two different rows of one user.');
        }

        if (abs($a->post_date->diffInDays($b->post_date)) > self::MANUAL_WINDOW_DAYS) {
            throw new TransferLegsTooFarApartException(__('The two transactions are more than :days days apart.', ['days' => self::MANUAL_WINDOW_DAYS]));
        }

        $linked = DB::transaction(function () use ($a, $b, $source): bool {
            // current(): a version superseded by an edit since the caller picked it is not
            // found, so the link is refused instead of pairing an obsolete row.
            $rows = Transaction::query()
                ->current()
                ->whereIn('id', [$a->id, $b->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->with('category.parent', 'account')
                ->get()
                ->keyBy('id');

            $first = $rows->get($a->id);
            $second = $rows->get($b->id);

            if (! $first instanceof Transaction || ! $second instanceof Transaction) {
                return false;
            }

            if ($this->isSplitRow($first) || $this->isSplitRow($second)) {
                return false;
            }

            if ($first->transfer_pair_id === $second->id && $second->transfer_pair_id === $first->id) {
                return true;
            }

            if ($first->transfer_pair_id !== null || $second->transfer_pair_id !== null) {
                return false;
            }

            if ($source === TransferLinkSource::Rule && ($this->isRejected($first) || $this->isRejected($second))) {
                return false;
            }

            // Any pending suggestion involving either leg is superseded by this link.
            Transaction::query()
                ->whereIn('suggested_pair_id', [$first->id, $second->id])
                ->update(['suggested_pair_id' => null, 'transfer_link_source' => null]);

            $kinds = $this->confirmedKinds($first, $second);

            foreach ([[$first, $second], [$second, $first]] as [$leg, $other]) {
                $leg->forceFill([
                    'transfer_pair_id' => $other->id,
                    'transfer_link_source' => $source,
                    'suggested_pair_id' => null,
                ] + ($kinds[$leg->id] ?? []) + $this->inheritedTransferCategory($leg, $other));
                $leg->propagateCategoryChange = ! array_key_exists('category_id', $kinds[$leg->id] ?? []);
                $leg->save();
            }

            return true;
        }, TransferKinds::TRANSACTION_ATTEMPTS);

        if ($linked) {
            $a->refresh();
            $b->refresh();
        }

        return $linked;
    }

    /**
     * Records a "possible transfer". Deliberately leaves transfer_pair_id null so the
     * pair keeps its current treatment in every total until the user confirms it.
     *
     * @return bool false when either row was linked, suggested or rejected in the meantime
     *
     * @throws Throwable
     */
    public function suggest(Transaction $a, Transaction $b): bool
    {
        if ($a->user_id !== $b->user_id || $a->id === $b->id) {
            throw new InvalidArgumentException('Transfer legs must be two different rows of one user.');
        }

        if (abs($a->post_date->diffInDays($b->post_date)) > self::WINDOW_DAYS) {
            return false;
        }

        return DB::transaction(function () use ($a, $b): bool {
            $rows = Transaction::query()
                ->current()
                ->whereIn('id', [$a->id, $b->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($rows->count() !== 2 || $rows->contains(fn (Transaction $t): bool => $t->transfer_pair_id !== null
                || $t->suggested_pair_id !== null
                || $this->isRejected($t)
                || $this->isSplitRow($t))) {
                return false;
            }

            $a->forceFill(['suggested_pair_id' => $b->id, 'transfer_link_source' => TransferLinkSource::Suggested])->save();
            $b->forceFill(['suggested_pair_id' => $a->id, 'transfer_link_source' => TransferLinkSource::Suggested])->save();

            return true;
        });
    }

    /**
     * Pairs $transaction with a mirror leg on an untracked (hidden) account. The mirror is
     * a manual row, so it never counts anywhere a tracked ledger is read, and its account
     * balance stays whatever the user entered.
     *
     * @throws TransferLinkRefusedException when the row was linked elsewhere meanwhile or a real opposite row exists (nothing is created)
     * @throws ExchangeRateUnavailableException when the account is in another currency and no rate is known (nothing is created)
     * @throws Throwable
     */
    public function linkToUntrackedAccount(Transaction $transaction, Account $account, TransferLinkSource $source): Transaction
    {
        if ($account->user_id !== $transaction->user_id) {
            throw new InvalidArgumentException('Counterpart must be an account of the same user.');
        }

        $this->oppositeLegAmount($transaction, $account);
        $exchange = $this->warmedExchange($transaction, self::WINDOW_DAYS);

        return DB::transaction(function () use ($transaction, $account, $source, $exchange): Transaction {
            // The caller's Account model may be stale: re-read it under lock (trackAccount takes
            // the same lock before releasing mirrors) so no mirror lands on a just-tracked account.
            $lockedAccount = Account::query()->whereKey($account->id)->lockForUpdate()->first();

            if (! $lockedAccount instanceof Account || $lockedAccount->is_tracked) {
                throw new AccountNotUntrackedException('That account is now tracked; link to the real transaction instead.');
            }

            $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->first();

            if (! $locked instanceof Transaction || $locked->transfer_pair_id !== null) {
                throw new TransferAlreadyLinkedException('That row is already linked to another transaction.');
            }

            // Real rows win: a mirror next to an existing imported opposite leg is the duplicate
            // the whole feature exists to prevent.
            if ($this->candidatesFor($locked, includeRejected: true, crossCurrency: true, exchange: $exchange)->isNotEmpty()) {
                throw new RealOppositeRowExistsException('A matching transaction exists; link to it instead of a hidden account.');
            }

            $mirror = Transaction::query()->create($this->oppositeLegAttributes($transaction, $account));

            // Throwing rolls the mirror back: a failed link must never leave an orphan leg.
            if (! $this->link($transaction, $mirror, $source)) {
                throw new TransferAlreadyLinkedException('That row is already linked to another transaction.');
            }

            $this->history->rebuildForTransactions($mirror);

            return $mirror;
        });
    }

    /**
     * Creates the missing leg of a transfer on one of the user's tracked accounts and links
     * the two. The caller has already offered any real opposite row; this is the explicit
     * "no matching row, create it there" choice, so it is allowed for bank-feed rows too.
     *
     * @throws TransferLinkRefusedException when the row was linked elsewhere meanwhile
     * @throws ExchangeRateUnavailableException when the account is in another currency and no rate is known (nothing is created)
     * @throws Throwable
     */
    public function createMissingLeg(Transaction $transaction, Account $account, TransferLinkSource $source): Transaction
    {
        if ($account->user_id !== $transaction->user_id || $account->id === $transaction->account_id) {
            throw new InvalidArgumentException('The missing leg must go on a different account of the same user.');
        }

        $this->oppositeLegAmount($transaction, $account);
        $exchange = $this->warmedExchange($transaction, self::MANUAL_WINDOW_DAYS);

        return Transaction::query()->getConnection()->transaction(function () use ($transaction, $account, $source, $exchange): Transaction {
            $lockedAccount = Account::query()->whereKey($account->id)->lockForUpdate()->first();

            if (! $lockedAccount instanceof Account || ! $lockedAccount->is_tracked) {
                throw new AccountNotTrackedException(__('Pick a tracked account for the missing transaction.'));
            }

            $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->first();

            if (! $locked instanceof Transaction || $locked->transfer_pair_id !== null) {
                throw new TransferAlreadyLinkedException('That row is already linked to another transaction.');
            }

            $realMatch = $this->candidatesFor($locked, includeRejected: true, windowDays: self::MANUAL_WINDOW_DAYS, lockOnAccountId: $account->id, crossCurrency: true, exchange: $exchange)
                ->isNotEmpty();

            if ($realMatch) {
                throw new RealOppositeRowExistsException(__('A matching transaction appeared on that account; link to it instead.'));
            }

            $leg = Transaction::query()->create($this->oppositeLegAttributes($transaction, $account));

            if (! $this->link($transaction, $leg, $source)) {
                throw new TransferAlreadyLinkedException('That row is already linked to another transaction.');
            }

            $this->history->rebuildForTransactions($leg);

            return $leg;
        });
    }

    /**
     * Records a manual transfer: the two legs, linked with source Manual, plus one expense
     * per non-zero fee on that fee's own account, all in one DB transaction with the balance
     * history rebuild. With an idempotency key a repeat of the same submission returns the
     * first debit leg and writes nothing; the same key with other details is refused.
     *
     * @return Transaction the debit leg, on the source account
     *
     * @throws IdempotencyKeyReusedException when the key was already used with other details
     * @throws InvalidArgumentException when the accounts are not two different accounts of the user, the amount is not positive or a fee is negative
     * @throws Throwable
     */
    public function create(NewTransfer $transfer, ?string $idempotencyKey = null): Transaction
    {
        if ($transfer->fromAccountId === $transfer->toAccountId
            || $transfer->amount <= 0
            || $transfer->sourceFee < 0
            || $transfer->destinationFee < 0) {
            throw new InvalidArgumentException('A transfer needs two different accounts, a positive amount and non-negative fees.');
        }

        if ($idempotencyKey !== null) {
            $existing = $this->idempotency->matching($transfer->userId, $idempotencyKey, $transfer->fromAccountId, $transfer->fingerprint());

            if ($existing instanceof Transaction) {
                return $existing;
            }
        }

        $this->assertTransferRateKnown($transfer);

        try {
            return DB::transaction(fn (): Transaction => $this->writeTransfer($transfer, $idempotencyKey), TransferKinds::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException $e) {
            $existing = $idempotencyKey === null
                ? null
                : $this->idempotency->matching($transfer->userId, $idempotencyKey, $transfer->fromAccountId, $transfer->fingerprint());

            return $existing ?? throw $e;
        }
    }

    /**
     * Deletes the fee expenses booked against these transfer legs, with every version of each
     * fee and every line a split of a fee made, and rebuilds the history of the accounts they
     * sat on. Every version goes, so deleting an edited fee does not hand current status back
     * to an earlier version.
     *
     * @throws Throwable
     */
    public function removeFees(Transaction ...$legs): void
    {
        if ($legs === []) {
            return;
        }

        DB::transaction(function () use ($legs): void {
            $fees = Transaction::query()
                ->where('user_id', $legs[0]->user_id)
                ->whereIn('transfer_fee_for_id', array_map(static fn (Transaction $leg): int => $leg->id, $legs))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($fees->isEmpty()) {
                return;
            }

            $children = Transaction::query()
                ->where('user_id', $legs[0]->user_id)
                ->whereIn('split_parent_id', $fees->pluck('id')->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ([...$fees, ...$children] as $row) {
                $row->delete();
            }

            $this->history->rebuildForTransactions(...$fees->all(), ...$children->all());
        });
    }

    /**
     * A manual or CSV row was versioned: a pending suggestion follows the new version, and is
     * dropped on both sides when the edit changed what the pair was matched on.
     *
     * @throws Throwable
     */
    public function followEditedVersion(Transaction $parent, Transaction $child): void
    {
        Transaction::query()->getConnection()->transaction(function () use ($parent, $child): void {
            $this->followVersion($parent, $child);

            $matchChanged = $parent->account_id !== $child->account_id
                || abs($parent->amount) !== abs($child->amount)
                || $parent->direction !== $child->direction
                || ! $parent->post_date->isSameDay($child->post_date);

            if (! $matchChanged || $child->suggested_pair_id === null) {
                return;
            }

            $partner = $this->lockedPartner($child, 'suggested_pair_id');

            foreach (array_filter([$child, $partner]) as $leg) {
                $leg->forceFill(['suggested_pair_id' => null, 'transfer_link_source' => null])->save();
            }
        });
    }

    /**
     * A transfer leg is being deleted. A partner on a tracked account survives as an ordinary
     * transaction, stamped Unlinked like unlink() so a Transfer category no longer keeps it out
     * of the totals, and it can still be linked by hand. A partner that is a mirror (a manual
     * row on an untracked account: ledger bookkeeping that is never listed) goes with the row.
     *
     * @throws Throwable
     */
    public function dissolve(Transaction $deleted): void
    {
        Transaction::query()->getConnection()->transaction(function () use ($deleted): void {
            $partner = $this->lockedPartner($deleted, 'transfer_pair_id');

            $this->removeFees(...array_filter([$deleted, $partner]));

            if (! $partner instanceof Transaction) {
                return;
            }

            if ($this->isMirror($partner)) {
                $partner->delete();
                $this->history->rebuildForTransactions($partner);

                return;
            }

            $partner->forceFill(['transfer_pair_id' => null, 'transfer_link_source' => TransferLinkSource::Unlinked, 'kind' => TransactionKind::Standard])->save();
        });
    }

    /**
     * A versioned transfer leg is being deleted, so its parent becomes the current row again.
     * That parent and the partner version it was paired with still point at each other, but
     * the partner has since been superseded: unlink both so the restored row is not left as
     * half of a transfer whose other leg is no longer current.
     */
    public function releaseRestoredVersion(Transaction $deleted): void
    {
        if ($deleted->parent_transaction_id === null) {
            return;
        }

        Transaction::query()
            ->where('user_id', $deleted->user_id)
            ->whereNotNull('transfer_pair_id')
            ->where(fn ($q) => $q->whereKey($deleted->parent_transaction_id)
                ->orWhere('transfer_pair_id', $deleted->parent_transaction_id))
            ->update(['transfer_pair_id' => null, 'transfer_link_source' => TransferLinkSource::Unlinked->value, 'kind' => TransactionKind::Standard->value]);
    }

    /**
     * A user's explicit choice: link to the hidden account now (Confirmed) and remember
     * account -> hidden account so later matching rows link themselves via the rule pass.
     *
     * @throws Throwable
     */
    public function linkToHiddenAccount(Transaction $transaction, Account $account): Transaction
    {
        $this->oppositeLegAmount($transaction, $account);
        $this->warmedExchange($transaction, self::WINDOW_DAYS);

        return DB::transaction(function () use ($transaction, $account): Transaction {
            $mirror = $this->linkToUntrackedAccount($transaction, $account, TransferLinkSource::Confirmed);
            $this->rememberRule($transaction, $mirror);

            return $mirror;
        });
    }

    /**
     * Confirms a suggestion in one locked state transition: both rows are re-read under lock and
     * must still reference each other through suggested_pair_id (or already be linked to each
     * other), so a concurrent "Not a transfer" or another confirm cannot be overwritten. Only a
     * pending suggestion writes anything: once the link is written the two rules (one per
     * direction) are remembered. Confirming an already-confirmed pair returns true and does nothing.
     *
     * @return bool false when there is nothing to confirm any more or a leg was claimed elsewhere
     *
     * @throws Throwable
     */
    public function confirm(Transaction $transaction): bool
    {
        return DB::transaction(function () use ($transaction): bool {
            $pair = $this->lockedPartner($transaction, 'suggested_pair_id');

            if (! $pair instanceof Transaction) {
                // A repeat confirm of an already-confirmed pair is a pure no-op (true, nothing
                // written): it must not recreate a rule the user deleted meanwhile. A manual or
                // rule link was never reviewed and does not count as confirmed.
                $linked = $this->lockedPartner($transaction, 'transfer_pair_id');

                return $linked instanceof Transaction
                    && $linked->transfer_link_source === TransferLinkSource::Confirmed
                    && $transaction->refresh()->transfer_link_source === TransferLinkSource::Confirmed;
            }

            if (! $this->link($transaction, $pair, TransferLinkSource::Confirmed)) {
                return false;
            }

            $this->rememberRule($transaction, $pair);
            $this->rememberRule($pair, $transaction);

            return true;
        });
    }

    /**
     * Non-destructive unlink. Imported rows always survive; a mirror leg on an
     * untracked account (created by linkToUntrackedAccount) is removed with the link.
     *
     * @throws Throwable
     */
    public function unlink(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            if ($transaction->transfer_pair_id === null) {
                $self = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->first();

                // Linked (or gone) in the meantime: this call no longer describes the row.
                if ($self instanceof Transaction && $self->transfer_pair_id === null) {
                    $self->forceFill(['transfer_link_source' => TransferLinkSource::Unlinked])->save();
                }

                return;
            }

            // Both legs are re-read under lock; if they no longer point at each other (linked
            // elsewhere, versioned or already unlinked) nothing is written.
            $pair = $this->lockedPartner($transaction, 'transfer_pair_id');

            if (! $pair instanceof Transaction) {
                return;
            }

            $transaction->refresh()->forceFill([
                'transfer_pair_id' => null,
                'transfer_link_source' => TransferLinkSource::Unlinked,
                'kind' => TransactionKind::Standard,
            ])->save();

            $this->removeFees($transaction, $pair);

            if ($this->isMirror($pair)) {
                $pair->delete();
                $this->history->rebuildForTransactions($pair);

                return;
            }

            $pair->forceFill([
                'transfer_pair_id' => null,
                'transfer_link_source' => TransferLinkSource::Unlinked,
                'kind' => TransactionKind::Standard,
            ])->save();
        });
    }

    /**
     * Remembers a row as "not a transfer" so it is never suggested again. Rejecting a
     * suggestion is one locked transition that re-verifies the pair still suggests each other;
     * if it was confirmed or rejected meanwhile nothing is written, so a confirmed pair can
     * never be stamped Unlinked while keeping its links and rules. The rows are untouched.
     *
     * @return bool false when nothing was written (the row is linked, gone or was already resolved)
     *
     * @throws Throwable
     */
    public function markNotTransfer(Transaction $transaction): bool
    {
        return DB::transaction(function () use ($transaction): bool {
            $partner = $this->lockedPartner($transaction, 'suggested_pair_id');

            if ($partner instanceof Transaction) {
                foreach ([$transaction->refresh(), $partner] as $leg) {
                    $leg->forceFill(['suggested_pair_id' => null, 'transfer_link_source' => TransferLinkSource::Unlinked])->save();
                }

                return true;
            }

            // No pending suggestion to reject. Stamp the row only if, under lock, it is still
            // unpaired and unsuggested (an unmatched Transfer row). A pair confirmed or linked
            // elsewhere meanwhile is never undone here: that is what unlink() is for.
            // Stamp the row the queue actually shows: if an edit versioned it since the caller
            // resolved it, that is the current version, not the id we were handed.
            $current = Transaction::findCurrentVersion($transaction->id, $transaction->user_id);
            $self = $current instanceof Transaction
                ? Transaction::query()->whereKey($current->id)->lockForUpdate()->first()
                : null;

            if (! $self instanceof Transaction || $self->transfer_pair_id !== null) {
                return false;
            }

            if ($self->suggested_pair_id !== null) {
                // A live suggestion is resolved by the branch above; a pointer to a deleted or
                // non-reciprocating row is dangling and is cleared here.
                $live = Transaction::query()
                    ->whereKey($self->suggested_pair_id)
                    ->where('suggested_pair_id', $self->id)
                    ->exists();

                if ($live) {
                    return false;
                }

                $self->suggested_pair_id = null;
            }

            $self->forceFill(['transfer_link_source' => TransferLinkSource::Unlinked])->save();

            return true;
        });
    }

    /**
     * A bank-feed row was versioned ($child copies $parent). The partner must follow the new
     * current version, but only for a pointer that still holds under lock: a link or suggestion
     * that was confirmed, rejected or unlinked meanwhile is cleared from the child, never
     * resurrected on the partner.
     *
     * @throws Throwable
     */
    public function followVersion(Transaction $parent, Transaction $child): void
    {
        DB::transaction(function () use ($parent, $child): void {
            $columns = ['transfer_pair_id', 'suggested_pair_id'];

            // Unlocked peek only to learn which rows to lock; every decision below uses the
            // locked copies, so a link written after the caller read $parent is not lost.
            $peek = Transaction::query()->whereKey($parent->id)->first();
            $ids = collect([$parent->id])
                ->merge($peek instanceof Transaction ? collect($columns)->map(fn (string $c): mixed => $peek->{$c}) : [])
                ->filter()
                ->unique()
                ->all();

            $rows = Transaction::query()
                ->where('user_id', $parent->user_id)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $freshParent = $rows->get($parent->id);

            if (! $freshParent instanceof Transaction) {
                return;
            }

            foreach ($columns as $column) {
                $partnerId = $freshParent->{$column};
                $partner = $partnerId === null ? null : $rows->get($partnerId);

                if ($partner instanceof Transaction && $partner->{$column} === $freshParent->id) {
                    $partner->forceFill([$column => $child->id])->save();
                    $child->forceFill([$column => $partner->id]);

                    continue;
                }

                $child->forceFill([$column => null]);
            }

            $child->forceFill(['transfer_link_source' => $freshParent->transfer_link_source]);

            if ($child->isDirty()) {
                $child->save();
            }
        });
    }

    /**
     * An untracked account is being converted to a tracked one. A transfer is never
     * re-exposed: a synthetic mirror is replaced only when exactly one real opposite row
     * exists for the original (the original is then linked to it directly). Otherwise the
     * pair is kept and the mirror simply becomes a manual row on the now-tracked account.
     * Call after the account is marked tracked so its own rows count as candidates.
     *
     * @return int Number of mirror legs replaced by a real row
     *
     * @throws Throwable
     */
    public function releaseMirrors(Account $account): int
    {
        return DB::transaction(function () use ($account): int {
            // Only synthetic mirrors: a manual leg whose partner is a bank-feed row. A transfer
            // the user typed by hand (manual on both sides) is history they entered; it stays.
            $mirrors = Transaction::query()
                ->where('account_id', $account->id)
                ->where('source', TransactionSource::Manual)
                ->whereNotNull('transfer_pair_id')
                ->whereHas('transferPair', fn ($partner) => $partner
                    ->whereIn('source', array_map(fn (TransactionSource $s): string => $s->value, TransactionSource::bankFeed())))
                ->get();

            $replaced = 0;
            $deletedMirrors = [];

            foreach ($mirrors as $mirror) {
                $original = Transaction::query()->whereKey($mirror->transfer_pair_id)->lockForUpdate()->first();

                if (! $original instanceof Transaction || $original->transfer_pair_id !== $mirror->id) {
                    continue;
                }

                $real = $this->candidatesFor($original);

                if ($real->count() !== 1) {
                    continue;
                }

                $candidate = Transaction::query()->whereKey($real->first()->id)->lockForUpdate()->first();

                if (! $candidate instanceof Transaction || $candidate->transfer_pair_id !== null) {
                    continue;
                }

                $source = $original->transfer_link_source ?? TransferLinkSource::Manual;

                // Guarded by the mirror id so a concurrent unlink is never overwritten.
                $released = Transaction::query()
                    ->whereKey($original->id)
                    ->where('transfer_pair_id', $mirror->id)
                    ->update(['transfer_pair_id' => null, 'transfer_link_source' => null]);

                if ($released === 0) {
                    continue;
                }

                $mirror->delete();
                $deletedMirrors[] = $mirror;
                $this->link($original->refresh(), $candidate, $source);
                $replaced++;
            }

            if ($deletedMirrors !== []) {
                $this->history->rebuildForTransactions(...$deletedMirrors);
            }

            return $replaced;
        });
    }

    /** @return Builder<Transaction> */
    private function candidateQuery(Transaction $transaction, bool $includeRejected, int $windowDays, ?int $lockOnAccountId): Builder
    {
        $query = Transaction::query()
            ->where('user_id', $transaction->user_id)
            ->current()
            ->where('id', '!=', $transaction->id)
            ->where('account_id', '!=', $transaction->account_id)
            ->where('direction', $transaction->direction === TransactionDirection::Debit
                ? TransactionDirection::Credit
                : TransactionDirection::Debit)
            ->whereBetween('post_date', [
                $transaction->post_date->subDays($windowDays)->toDateString(),
                $transaction->post_date->addDays($windowDays)->toDateString(),
            ]);

        if ($includeRejected) {
            $query->whereNull('transfer_pair_id')->notSplit()->onTrackedAccounts();
        } else {
            $query->linkable();
        }

        if ($lockOnAccountId !== null) {
            $query->where('account_id', $lockOnAccountId)->orderBy('id')->lockForUpdate();
        }

        return $query;
    }

    private function writeTransfer(NewTransfer $transfer, ?string $idempotencyKey): Transaction
    {
        $this->history->lockAccounts($transfer->fromAccountId, $transfer->toAccountId);

        if ($idempotencyKey !== null) {
            $existing = $this->idempotency->matching($transfer->userId, $idempotencyKey, $transfer->fromAccountId, $transfer->fingerprint());

            if ($existing instanceof Transaction) {
                return $existing;
            }
        }

        $accounts = Account::query()
            ->where('user_id', $transfer->userId)
            ->whereIn('id', [$transfer->fromAccountId, $transfer->toAccountId])
            ->get()
            ->keyBy('id');

        if ($accounts->count() !== 2) {
            throw new InvalidArgumentException('A transfer moves money between two accounts of the same user.');
        }

        $creditAmount = $this->incomingAmount($transfer, $accounts->get($transfer->fromAccountId)->currency, $accounts->get($transfer->toAccountId)->currency);

        $feeCategoryId = $transfer->sourceFee > 0 || $transfer->destinationFee > 0
            ? $this->feeCategory->forUser($transfer->userId)->id
            : null;

        $shared = [
            'planned_transaction_id' => $transfer->plannedTransactionId,
            'user_id' => $transfer->userId,
            'category_id' => $transfer->categoryId,
            'amount' => $transfer->amount,
            'description' => $transfer->description,
            'post_date' => $transfer->postDate,
            'status' => TransactionStatus::Posted,
            'source' => TransactionSource::Manual,
            'notes' => $transfer->notes,
        ];

        $kinds = $this->kinds->attributes($transfer->userId, $accounts->get($transfer->fromAccountId)->type, $accounts->get($transfer->toAccountId)->type, $transfer->categoryId);

        $debit = Transaction::query()->create([...$shared, ...$kinds['outflow']] + [
            'account_id' => $transfer->fromAccountId,
            'direction' => TransactionDirection::Debit,
            'idempotency_key' => $idempotencyKey,
            'idempotency_fingerprint' => $idempotencyKey === null ? null : $transfer->fingerprint(),
        ]);

        $credit = Transaction::query()->create([...$shared, 'amount' => $creditAmount, ...$kinds['inflow']] + [
            'account_id' => $transfer->toAccountId,
            'direction' => TransactionDirection::Credit,
        ]);

        $manual = ['transfer_link_source' => TransferLinkSource::Manual];

        $debit->update(['transfer_pair_id' => $credit->id] + $manual);
        $credit->update(['transfer_pair_id' => $debit->id] + $manual);

        $fees = $this->createFees($transfer, $debit, $credit, $feeCategoryId);

        $this->history->rebuildForTransactions($debit, $credit, ...$fees);

        return $debit;
    }

    /**
     * What the destination account receives: the amount itself for one currency, otherwise the
     * amount converted at the custom rate or, failing that, the transfer date's rate.
     *
     * @throws ExchangeRateUnavailableException when the currencies differ and no rate is known
     */
    private function incomingAmount(NewTransfer $transfer, string $fromCurrency, string $toCurrency): int
    {
        return $this->exchange->incoming($transfer->userId, $transfer->amount, $fromCurrency, $toCurrency, CarbonImmutable::parse($transfer->postDate), $transfer->exchangeRate)
            ?? throw new ExchangeRateUnavailableException($transfer->exchangeRate === null
                ? __('No exchange rate is available for this date. Enter a rate.')
                : __('The converted amount rounds to zero. Enter a larger amount or a different rate.'));
    }

    /**
     * Looks the rate up before any lock is taken: a provider call can take seconds and must not
     * hold the account rows. The write recomputes the amount under lock and reads the stored rate.
     *
     * @throws ExchangeRateUnavailableException when the currencies differ and no rate is known
     */
    private function assertTransferRateKnown(NewTransfer $transfer): void
    {
        $currencies = Account::query()
            ->where('user_id', $transfer->userId)
            ->whereIn('id', [$transfer->fromAccountId, $transfer->toAccountId])
            ->pluck('currency', 'id');

        if ($currencies->count() === 2) {
            $this->incomingAmount($transfer, $currencies->get($transfer->fromAccountId), $currencies->get($transfer->toAccountId));
        }
    }

    /**
     * A memoising exchange that has already looked up every rate the cross-currency recheck will
     * need, so the recheck under lock reads memoised answers instead of calling a provider.
     */
    private function warmedExchange(Transaction $transaction, int $windowDays): TransferExchange
    {
        $exchange = $this->exchange->memoising();
        $this->crossCurrencyCandidatesFor($transaction, includeRejected: true, windowDays: $windowDays, exchange: $exchange);

        return $exchange;
    }

    /** @return list<Transaction> */
    private function createFees(NewTransfer $transfer, Transaction $debit, Transaction $credit, ?int $categoryId): array
    {
        $fees = [];

        foreach ([[$debit, $transfer->sourceFee], [$credit, $transfer->destinationFee]] as [$leg, $amount]) {
            if ($amount <= 0) {
                continue;
            }

            $fees[] = Transaction::query()->create([
                'user_id' => $transfer->userId,
                'account_id' => $leg->account_id,
                'category_id' => $categoryId,
                'amount' => $amount,
                'direction' => TransactionDirection::Debit,
                'description' => __('Transfer fee'),
                'post_date' => $transfer->postDate,
                'status' => TransactionStatus::Posted,
                'source' => TransactionSource::Manual,
                'transfer_fee_for_id' => $leg->id,
            ]);
        }

        return $fees;
    }

    /**
     * The kind (and, for an uncategorised investment contribution, the category) each leg of a
     * confirmed link takes, by leg id. Empty when the legs are not one outflow and one inflow.
     *
     * @return array<int, array<string, mixed>>
     */
    private function confirmedKinds(Transaction $a, Transaction $b): array
    {
        if ($a->direction === $b->direction) {
            return [];
        }

        [$outflow, $inflow] = $a->direction === TransactionDirection::Debit ? [$a, $b] : [$b, $a];

        $attributes = $this->kinds->attributes($outflow->user_id, $outflow->account->type, $inflow->account->type, $outflow->category_id);

        return [$outflow->id => $attributes['outflow'], $inflow->id => $attributes['inflow']];
    }

    /**
     * An uncategorised leg inherits the Transfer category of its partner.
     *
     * @return array<string, mixed>
     */
    private function inheritedTransferCategory(Transaction $leg, Transaction $other): array
    {
        if ($leg->category_id !== null || ! TransferSignal::hasTransferCategory($other)) {
            return [];
        }

        return ['category_id' => $other->category_id, 'category_source' => CategorySource::Rule];
    }

    /**
     * The opposite leg's attributes. In another currency than the row it carries the row's
     * amount converted at the user's rate for the row's date, in cents, in the account's currency.
     *
     * @return array<string, mixed>
     *
     * @throws ExchangeRateUnavailableException when the currencies differ and no rate is known
     */
    private function oppositeLegAttributes(Transaction $transaction, Account $account): array
    {
        return [
            'user_id' => $transaction->user_id,
            'account_id' => $account->id,
            'category_id' => $transaction->category_id,
            'amount' => $this->oppositeLegAmount($transaction, $account),
            'currency' => $account->currency,
            'direction' => $transaction->direction === TransactionDirection::Debit
                ? TransactionDirection::Credit
                : TransactionDirection::Debit,
            'description' => $transaction->description,
            'post_date' => $transaction->post_date,
            'source' => TransactionSource::Manual,
            'status' => TransactionStatus::Posted,
        ];
    }

    /**
     * The opposite leg's amount: the row's negated amount in one currency, otherwise converted at
     * the user's rate for the row's date. Called before any lock is taken to find the rate first.
     *
     * @throws ExchangeRateUnavailableException when the currencies differ and no rate is known
     */
    private function oppositeLegAmount(Transaction $transaction, Account $account): int
    {
        if (mb_strtoupper($transaction->currency) === mb_strtoupper($account->currency)) {
            return -$transaction->amount;
        }

        $converted = $this->exchange->incoming($transaction->user_id, abs($transaction->amount), $transaction->currency, $account->currency, $transaction->post_date)
            ?? throw new ExchangeRateUnavailableException(__('No exchange rate is available for this date. Pick an account in the same currency or enter the transfer with a rate.'));

        return $transaction->amount < 0 ? $converted : -$converted;
    }

    private function isRejected(Transaction $transaction): bool
    {
        return $transaction->transfer_link_source === TransferLinkSource::Unlinked;
    }

    /**
     * A split parent or one of its lines never takes part in a transfer. Read from the database
     * inside the caller's locks, not from a loaded relation.
     */
    private function isSplitRow(Transaction $transaction): bool
    {
        return $transaction->split_parent_id !== null
            || Transaction::query()->where('split_parent_id', $transaction->id)->exists();
    }

    /**
     * The partner of $transaction through $column, both rows locked in id order and verified
     * against their current database state: null unless the two still point at each other.
     */
    private function lockedPartner(Transaction $transaction, string $column): ?Transaction
    {
        $partnerId = $transaction->{$column};

        if ($partnerId === null) {
            return null;
        }

        $rows = Transaction::query()
            ->current()
            ->where('user_id', $transaction->user_id)
            ->whereIn('id', [$transaction->id, $partnerId])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $self = $rows->get($transaction->id);
        $partner = $rows->get($partnerId);

        if (! $self instanceof Transaction || ! $partner instanceof Transaction
            || $self->{$column} !== $partner->id || $partner->{$column} !== $self->id) {
            return null;
        }

        return $partner;
    }

    private function isMirror(Transaction $leg): bool
    {
        return $leg->source === TransactionSource::Manual
            && $leg->account()->where('is_tracked', false)->exists();
    }

    private function rememberRule(Transaction $from, Transaction $to): void
    {
        $pattern = TransferRule::patternFor($from->description);

        if ($pattern === '' || $from->account_id === $to->account_id) {
            return;
        }

        TransferRule::query()->firstOrCreate([
            'user_id' => $from->user_id,
            'account_id' => $from->account_id,
            'counterpart_account_id' => $to->account_id,
            'description_pattern' => mb_substr($pattern, 0, 255),
        ]);
    }
}
