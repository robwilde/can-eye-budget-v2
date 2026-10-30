<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Exceptions\PendingMergeRefusedException;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Models\User;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\Transfers\TransferLinker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Merges a pending transaction into the posted row that replaced it. The posted row stays (as a
 * new version): it keeps its amount, name and merchant, and takes the pending row's date and
 * category, the joined notes, and the pending row's tags and receipts.
 */
final readonly class PendingMergeService
{
    public const int PAGE_SIZE = 10;

    public const int SUGGESTION_DAYS = 3;

    public const float SUGGESTION_MAX_GROWTH = 0.25;

    private const int NAME_WORDS = 3;

    public function __construct(private AccountBalanceHistoryBuilder $history, private TransferLinker $transfers) {}

    /**
     * The single posted row up to 25% larger, within 3 days and sharing the first three words
     * of the name; null when none or more than one qualifies.
     */
    public function suggestionFor(Transaction $pending): ?Transaction
    {
        $amount = abs($pending->amount);

        $matches = $this->postedOnAccount($pending)
            ->where('direction', $pending->direction)
            ->whereBetween('post_date', [
                $pending->post_date->subDays(self::SUGGESTION_DAYS)->toDateString(),
                $pending->post_date->addDays(self::SUGGESTION_DAYS)->toDateString(),
            ])
            ->whereRaw('ABS(amount) BETWEEN ? AND ?', [$amount, (int) floor($amount * (1 + self::SUGGESTION_MAX_GROWTH))])
            ->get()
            ->filter(fn (Transaction $posted): bool => $this->leadingWords($posted->description) === $this->leadingWords($pending->description));

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * Posted rows of the same account the pending row could be merged into, newest first.
     *
     * @return Collection<int, Transaction>
     */
    public function candidatesFor(Transaction $pending, int $limit = self::PAGE_SIZE): Collection
    {
        return $this->postedOnAccount($pending)
            ->orderByDesc('post_date')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * @throws PendingMergeRefusedException
     */
    public function merge(User $user, int $pendingId, int $postedId): Transaction
    {
        return Transaction::query()->getConnection()->transaction(function () use ($user, $pendingId, $postedId): Transaction {
            $this->history->lockAccounts(
                Transaction::query()->where('user_id', $user->id)->whereKey($postedId)->value('account_id'),
            );

            $rows = Transaction::query()
                ->where('user_id', $user->id)
                ->current()
                ->whereIn('id', [$pendingId, $postedId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $pending = $rows->get($pendingId);
            $posted = $rows->get($postedId);

            if (! $pending instanceof Transaction || ! $posted instanceof Transaction) {
                throw new PendingMergeRefusedException(__('One of the transactions no longer exists.'));
            }

            $lineage = $this->lineageOf($pending);

            $this->assertMergeable($pending, $posted, $lineage);

            $child = $posted->createChild($this->mergedAttributes($pending, $posted));

            $this->transfers->followEditedVersion($posted, $child);

            TransactionAttachment::query()->whereIn('transaction_id', $lineage->modelKeys())->update(['transaction_id' => $child->id]);
            $child->tags()->syncWithoutDetaching($pending->tags()->pluck('tags.id')->all());

            $this->retire($lineage, $child);

            $account = Account::query()->where('user_id', $user->id)->find($posted->account_id);

            if ($account instanceof Account) {
                $this->history->rebuild($account, min($pending->post_date, $posted->post_date)->toImmutable());
            }

            return $child;
        });
    }

    /**
     * @param  Collection<int, Transaction>  $lineage
     *
     * @throws PendingMergeRefusedException
     */
    private function assertMergeable(Transaction $pending, Transaction $posted, Collection $lineage): void
    {
        if ($pending->status !== TransactionStatus::Pending || $posted->status !== TransactionStatus::Posted) {
            throw new PendingMergeRefusedException(__('Only a pending transaction can be merged into a posted one.'));
        }

        if ($pending->account_id !== $posted->account_id) {
            throw new PendingMergeRefusedException(__('Both transactions must be on the same account.'));
        }

        if ($pending->transfer_pair_id !== null || $posted->transfer_pair_id !== null) {
            throw new PendingMergeRefusedException(__('Transfers cannot be merged.'));
        }

        $attachments = TransactionAttachment::query()->whereIn('transaction_id', [...$lineage->modelKeys(), $posted->id])->count();

        if ($attachments > TransactionAttachment::MAX_PER_TRANSACTION) {
            throw new PendingMergeRefusedException(__('The merged transaction would have more than :max attachments.', ['max' => TransactionAttachment::MAX_PER_TRANSACTION]));
        }
    }

    /**
     * The pending row and every earlier version of it, newest first, locked.
     *
     * @return Collection<int, Transaction>
     */
    private function lineageOf(Transaction $pending): Collection
    {
        $lineage = new Collection([$pending]);
        $parentId = $pending->parent_transaction_id;

        while ($parentId !== null) {
            $parent = Transaction::query()->where('user_id', $pending->user_id)->lockForUpdate()->find($parentId);

            if (! $parent instanceof Transaction) {
                break;
            }

            $lineage->push($parent);
            $parentId = $parent->parent_transaction_id;
        }

        return $lineage;
    }

    /**
     * @param  Collection<int, Transaction>  $lineage
     */
    private function retire(Collection $lineage, Transaction $child): void
    {
        foreach ($lineage as $row) {
            $row->forceFill(['folded_into_transaction_id' => $child->id])->save();
            $row->delete();
        }
    }

    /** @return array<string, mixed> */
    private function mergedAttributes(Transaction $pending, Transaction $posted): array
    {
        $attributes = [
            'post_date' => $pending->post_date,
            'notes' => $this->joinedNotes($pending, $posted),
        ];

        if ($pending->category_id !== null) {
            $attributes['category_id'] = $pending->category_id;
            $attributes['category_source'] = $pending->category_source;
        }

        if ($posted->planned_transaction_id === null && $pending->planned_transaction_id !== null) {
            $attributes['planned_transaction_id'] = $pending->planned_transaction_id;
        }

        $attributes['locked_fields'] = $posted->lockedFieldsAfter($attributes);

        return $attributes;
    }

    private function joinedNotes(Transaction $pending, Transaction $posted): ?string
    {
        $notes = array_values(array_unique(array_filter(
            [$pending->notes, $posted->notes],
            static fn (?string $note): bool => $note !== null && $note !== '',
        )));

        return $notes === [] ? null : implode("\n", $notes);
    }

    /** @return Builder<Transaction> */
    private function postedOnAccount(Transaction $pending): Builder
    {
        return Transaction::query()
            ->where('user_id', $pending->user_id)
            ->current()
            ->where('account_id', $pending->account_id)
            ->where('status', TransactionStatus::Posted)
            ->whereNull('transfer_pair_id')
            ->whereKeyNot($pending->id);
    }

    private function leadingWords(string $description): string
    {
        return Str::of($description)->lower()->squish()->explode(' ')->take(self::NAME_WORDS)->implode(' ');
    }
}
