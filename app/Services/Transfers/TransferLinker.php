<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Enums\CategorySource;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransferLinkSource;
use App\Exceptions\AccountNotUntrackedException;
use App\Exceptions\RealOppositeRowExistsException;
use App\Exceptions\TransferAlreadyLinkedException;
use App\Exceptions\TransferLinkRefusedException;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\TransferRule;
use App\Support\Transfers\TransferSignal;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * Unlinked rows on tracked accounts that could be the opposite leg of $transaction:
     * same user, opposite direction, same absolute amount, within the date window,
     * different account, not already linked and not previously rejected.
     *
     * @return Collection<int, Transaction>
     */
    public function candidatesFor(Transaction $transaction, bool $includeRejected = false): Collection
    {
        $query = Transaction::query()
            ->where('user_id', $transaction->user_id)
            ->current()
            ->where('id', '!=', $transaction->id)
            ->where('account_id', '!=', $transaction->account_id)
            ->where('direction', $transaction->direction === TransactionDirection::Debit
                ? TransactionDirection::Credit
                : TransactionDirection::Debit)
            ->whereRaw('ABS(amount) = ?', [abs($transaction->amount)])
            ->whereBetween('post_date', [
                $transaction->post_date->subDays(self::WINDOW_DAYS)->toDateString(),
                $transaction->post_date->addDays(self::WINDOW_DAYS)->toDateString(),
            ]);

        if ($includeRejected) {
            $query->whereNull('transfer_pair_id')->onTrackedAccounts();
        } else {
            $query->linkable();
        }

        return $query->get();
    }

    /**
     * Links two rows as a transfer. Both rows are re-read under a lock, in a stable order, so
     * concurrent detection and modal saves cannot leave a leg pointing at a row that was
     * paired with someone else.
     *
     * @return bool false when either row was linked to a different row in the meantime, or
     *              (for automatic Rule links) was rejected by the user; nothing is written then
     *
     * @throws Throwable
     */
    public function link(Transaction $a, Transaction $b, TransferLinkSource $source): bool
    {
        if ($a->user_id !== $b->user_id || $a->id === $b->id) {
            throw new InvalidArgumentException('Transfer legs must be two different rows of one user.');
        }

        $linked = DB::transaction(function () use ($a, $b, $source): bool {
            // current(): a version superseded by an edit since the caller picked it is not
            // found, so the link is refused instead of pairing an obsolete row.
            $rows = Transaction::query()
                ->current()
                ->whereIn('id', [$a->id, $b->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->with('category.parent')
                ->get()
                ->keyBy('id');

            $first = $rows->get($a->id);
            $second = $rows->get($b->id);

            if (! $first instanceof Transaction || ! $second instanceof Transaction) {
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

            foreach ([[$first, $second], [$second, $first]] as [$leg, $other]) {
                $leg->forceFill([
                    'transfer_pair_id' => $other->id,
                    'transfer_link_source' => $source,
                    'suggested_pair_id' => null,
                ] + $this->inheritedTransferCategory($leg, $other));
                $leg->save();
            }

            return true;
        });

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

        return DB::transaction(function () use ($a, $b): bool {
            $rows = Transaction::query()
                ->current()
                ->whereIn('id', [$a->id, $b->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($rows->count() !== 2 || $rows->contains(fn (Transaction $t): bool => $t->transfer_pair_id !== null
                || $t->suggested_pair_id !== null
                || $this->isRejected($t))) {
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
     * @throws Throwable
     */
    public function linkToUntrackedAccount(Transaction $transaction, Account $account, TransferLinkSource $source): Transaction
    {
        if ($account->user_id !== $transaction->user_id) {
            throw new InvalidArgumentException('Counterpart must be an account of the same user.');
        }

        return DB::transaction(function () use ($transaction, $account, $source): Transaction {
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
            if ($this->candidatesFor($locked, includeRejected: true)->isNotEmpty()) {
                throw new RealOppositeRowExistsException('A matching transaction exists; link to it instead of a hidden account.');
            }

            $mirror = Transaction::query()->create([
                'user_id' => $transaction->user_id,
                'account_id' => $account->id,
                'category_id' => $transaction->category_id,
                'amount' => -$transaction->amount,
                'direction' => $transaction->direction === TransactionDirection::Debit
                    ? TransactionDirection::Credit
                    : TransactionDirection::Debit,
                'description' => $transaction->description,
                'post_date' => $transaction->post_date,
                'source' => TransactionSource::Manual,
                'status' => TransactionStatus::Posted,
            ]);

            // Throwing rolls the mirror back: a failed link must never leave an orphan leg.
            if (! $this->link($transaction, $mirror, $source)) {
                throw new TransferAlreadyLinkedException('That row is already linked to another transaction.');
            }

            return $mirror;
        });
    }

    /**
     * A user's explicit choice: link to the hidden account now (Confirmed) and remember
     * account -> hidden account so later matching rows link themselves via the rule pass.
     *
     * @throws Throwable
     */
    public function linkToHiddenAccount(Transaction $transaction, Account $account): Transaction
    {
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
            ])->save();

            if ($this->isMirror($pair)) {
                $pair->delete();

                return;
            }

            $pair->forceFill([
                'transfer_pair_id' => null,
                'transfer_link_source' => TransferLinkSource::Unlinked,
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
                $this->link($original->refresh(), $candidate, $source);
                $replaced++;
            }

            return $replaced;
        });
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

    private function isRejected(Transaction $transaction): bool
    {
        return $transaction->transfer_link_source === TransferLinkSource::Unlinked;
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
