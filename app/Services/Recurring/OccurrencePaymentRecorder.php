<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\Enums\OccurrenceStatus;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of occurrence payments, and the owner of the rules that close and reopen an occurrence
 * because of them. Every write re-scopes to the user, runs in one database transaction and locks the
 * transaction first, then the plan, then the occurrences, always through lockRows(), which is why deleting, unlinking
 * or editing a transaction and attaching it cannot deadlock. The cap is read under the transaction's lock, so
 * two attaches of one transaction cannot both see the same free amount.
 *
 * An occurrence closes as paid, automatically, when the payments reach what it expects (within a cent),
 * or when a single payment lands within the plan's tolerance of it. An automatic close is undone when
 * removing a payment disqualifies it; one a person made (mark paid, skip, missed) never is. Only a
 * payment that settles teaches the plan: partial payments teach nothing.
 */
final readonly class OccurrencePaymentRecorder
{
    public const int SETTLE_SLACK_CENTS = 1;

    public const int MAX_TOLERANCE_BPS = 2500;

    private const int MAX_ALIASES = 20;

    public function __construct(private PlannedOccurrenceGenerator $generator) {}

    /**
     * Applies up to $amount of a transaction (all it has not yet given to other occurrences when null) to
     * an open occurrence. Applying the same transaction to the same occurrence again adds to its payment.
     *
     * @throws ModelNotFoundException when the occurrence or the transaction is not the user's
     * @throws ValidationException
     */
    public function attach(User $user, int $transactionId, int $occurrenceId, ?int $amount = null): PlannedPayment
    {
        $planId = PlannedOccurrence::query()->forUser($user)->whereKey($occurrenceId)->value('planned_transaction_id')
            ?? throw (new ModelNotFoundException)->setModel(PlannedOccurrence::class);

        return DB::transaction(function () use ($user, $transactionId, $occurrenceId, $amount, $planId): PlannedPayment {
            [$transactions, $occurrences] = $this->lockRows([$transactionId], [$occurrenceId], $user, live: true, planIds: [$planId]);
            $occurrence = $occurrences->first() ?? throw (new ModelNotFoundException)->setModel(PlannedOccurrence::class);
            $transaction = $transactions->first() ?? throw (new ModelNotFoundException)->setModel(Transaction::class);
            $this->requireOpen($occurrence);

            if ($transaction->currency !== $occurrence->plannedTransaction->account->currency) {
                $this->refuse(__('A payment in another currency cannot be applied to this bill yet.'));
            }

            if ($transaction->direction !== $occurrence->plannedTransaction->direction) {
                $this->refuse(__('A payment in the other direction cannot pay this bill.'));
            }

            $free = abs($transaction->amount) - (int) PlannedPayment::query()->where('transaction_id', $transaction->id)->lockForUpdate()->sum('amount');
            $applied = $amount ?? $free;

            if ($applied <= 0) {
                $this->refuse($amount === null ? __("That would allocate more than the transaction's amount.") : __('The amount must be greater than zero.'));
            }

            if ($applied > $free) {
                $this->refuse(__("That would allocate more than the transaction's amount."));
            }

            $payment = PlannedPayment::query()
                ->where('planned_occurrence_id', $occurrence->id)
                ->where('transaction_id', $transaction->id)
                ->lockForUpdate()
                ->first();

            if ($payment instanceof PlannedPayment) {
                $payment->update(['amount' => $payment->amount + $applied]);
            } else {
                $payment = PlannedPayment::query()->create([
                    'planned_occurrence_id' => $occurrence->id,
                    'transaction_id' => $transaction->id,
                    'amount' => $applied,
                    'paid_on' => $transaction->post_date,
                ]);
            }

            if ($this->closeIfSettled($occurrence)) {
                $this->learn($occurrence->plannedTransaction, $transaction, $occurrence);
            }

            return $payment;
        });
    }

    /**
     * Records money paid without a transaction, today or on an earlier date.
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function record(User $user, int $occurrenceId, int $amount, ?string $paidOn = null): PlannedPayment
    {
        if ($amount <= 0) {
            $this->refuse(__('The amount must be greater than zero.'));
        }

        $date = $paidOn === null || $paidOn === '' ? $user->today() : $this->parseDate($paidOn);

        if ($date->greaterThan($user->today())) {
            $this->refuse(__('A payment cannot be dated in the future.'));
        }

        return DB::transaction(function () use ($user, $occurrenceId, $amount, $date): PlannedPayment {
            $occurrence = $this->lockOccurrence($user, $occurrenceId);
            $this->requireOpen($occurrence);

            $payment = PlannedPayment::query()->create([
                'planned_occurrence_id' => $occurrence->id,
                'transaction_id' => null,
                'amount' => $amount,
                'paid_on' => $date,
            ]);

            $this->closeIfSettled($occurrence);

            return $payment;
        });
    }

    /**
     * Pays whatever an open occurrence still lacks and closes it as paid by hand, so removing a payment
     * later never reopens it.
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function markPaid(User $user, int $occurrenceId): PlannedOccurrence
    {
        return DB::transaction(function () use ($user, $occurrenceId): PlannedOccurrence {
            $occurrence = $this->lockOccurrence($user, $occurrenceId);
            $this->requireOpen($occurrence);

            $remainder = $this->expected($occurrence) - $this->paid($occurrence);

            if ($remainder > 0) {
                PlannedPayment::query()->create([
                    'planned_occurrence_id' => $occurrence->id,
                    'transaction_id' => null,
                    'amount' => $remainder,
                    'paid_on' => $user->today(),
                ]);
            }

            $occurrence->update([
                'status' => OccurrenceStatus::Paid,
                'closed_at' => now(),
                'closed_manually' => true,
            ]);

            return $occurrence;
        });
    }

    /**
     * Deletes one payment and reopens its occurrence when it was closed automatically and no longer
     * qualifies.
     *
     * @throws ModelNotFoundException
     */
    public function removePayment(User $user, int $paymentId): PlannedOccurrence
    {
        $payment = PlannedPayment::query()->forUser($user)->whereKey($paymentId)->firstOrFail();

        return DB::transaction(function () use ($user, $payment): PlannedOccurrence {
            [, $occurrences] = $this->lockRows($payment->transaction_id === null ? [] : [$payment->transaction_id], [$payment->planned_occurrence_id], $user);
            $occurrence = $occurrences->first() ?? throw (new ModelNotFoundException)->setModel(PlannedOccurrence::class);

            PlannedPayment::query()->where('planned_occurrence_id', $occurrence->id)->whereKey($payment->id)->delete();
            $this->reopenIfDisqualified($occurrence);

            return $occurrence;
        });
    }

    /**
     * Re-checks an occurrence after something other than a payment changed what it expects.
     */
    public function reconcile(PlannedOccurrence $occurrence): void
    {
        DB::transaction(function () use ($occurrence): void {
            [, $locked] = $this->lockRows([], [$occurrence->id]);
            $locked = $locked->first() ?? throw (new ModelNotFoundException)->setModel(PlannedOccurrence::class);

            if ($locked->status === OccurrenceStatus::Scheduled) {
                $this->closeIfSettled($locked);
            } else {
                $this->reopenIfDisqualified($locked);
            }
        });
    }

    /**
     * Pays the open occurrence nearest a transaction that was just linked to the plan, creating it when it
     * was never stored. Quiet when there is nothing to pay or the payment is refused: linking never fails
     * because of it.
     */
    public function claim(Transaction $transaction, PlannedTransaction $plan): ?PlannedPayment
    {
        if ($transaction->user_id !== $plan->user_id) {
            return null;
        }

        return DB::transaction(function () use ($transaction, $plan): ?PlannedPayment {
            [$transactions] = $this->lockRows([$transaction->id], [], planIds: [$plan->id]);

            $user = User::query()->find($plan->user_id);

            if ($transactions->isEmpty() || ! $user instanceof User || PlannedPayment::query()->where('transaction_id', $transaction->id)->exists()) {
                return null;
            }

            $post = CarbonImmutable::instance($transaction->post_date)->startOfDay();

            $candidates = $plan->scheduledBetween($post->subDays($plan->match_late_days), $post->addDays($plan->match_early_days))
                ->sortBy(static fn ($scheduled): int => abs((int) $post->diffInDays($scheduled->due, false)))
                ->values();

            foreach ($candidates as $scheduled) {
                $this->generator->generate($plan, $scheduled->due, $scheduled->due);

                $occurrence = PlannedOccurrence::query()
                    ->where('planned_transaction_id', $plan->id)
                    ->whereDate('original_date', $scheduled->original)
                    ->first();

                if (! $occurrence instanceof PlannedOccurrence || $occurrence->status !== OccurrenceStatus::Scheduled) {
                    continue;
                }

                try {
                    return $this->attach($user, $transaction->id, $occurrence->id);
                } catch (ValidationException|ModelNotFoundException) {
                    return null;
                }
            }

            return null;
        });
    }

    /**
     * Locks, in ascending id, the transaction rows a batch is about to change and the rows its deletes reach: the
     * rows themselves, their split children, version parents and children, suggested pairs, transfer partners (and
     * their parents), the fees of their legs (and the fees' split children). A batch that
     * handles many transactions in one database transaction calls this first, inside that transaction, so every
     * transaction lock comes before any occurrence lock its payment work takes later.
     *
     * @param  list<int|string>  $transactionIds
     */
    public function lockTransactionBatch(array $transactionIds): void
    {
        $ids = array_values(array_unique(array_map(intval(...), $transactionIds)));

        if ($ids === []) {
            return;
        }

        $reached = Transaction::withTrashed()
            ->where(fn ($query) => $query->whereKey($ids)->orWhereIn('split_parent_id', $ids)->orWhereIn('parent_transaction_id', $ids)->orWhereIn('suggested_pair_id', $ids))
            ->get(['id', 'transfer_pair_id', 'parent_transaction_id']);

        $partners = $reached->pluck('transfer_pair_id')->filter()->all();
        $parents = $reached->pluck('parent_transaction_id')->filter()->all();
        $legs = array_values(array_unique(array_merge($ids, $partners)));

        $fees = Transaction::withTrashed()
            ->where(fn ($query) => $query->whereIn('transfer_fee_for_id', $legs)->orWhereIn('transfer_pair_id', $parents))
            ->get(['id', 'parent_transaction_id']);

        $feeChildren = Transaction::withTrashed()->whereIn('split_parent_id', $fees->pluck('id')->all())->pluck('id')->all();
        $partnerParents = Transaction::withTrashed()->whereKey($partners)->pluck('parent_transaction_id')->filter()->all();

        $this->lockRows(
            array_merge($ids, $reached->pluck('id')->all(), $partners, $parents, $fees->pluck('id')->all(), $feeChildren, $partnerParents),
            [],
        );
    }

    /**
     * Claims for several transactions that were just linked to the plan together (an accepted detected plan):
     * every transaction row is locked first, in ascending id, before any occurrence is.
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    public function claimAll(Collection $transactions, PlannedTransaction $plan): void
    {
        DB::transaction(function () use ($transactions, $plan): void {
            $this->lockRows($transactions->modelKeys(), [], planIds: [$plan->id]);

            foreach ($transactions->sortBy('post_date') as $transaction) {
                $this->release($transaction, $plan->id);
                $this->claim($transaction, $plan);
            }
        });
    }

    /**
     * Pays the occurrence the user opened (the one due on $occurrenceDate, or snoozed to it), creating it when
     * it was never stored. When the day has no occurrence of the plan it falls back to the nearest open one.
     */
    public function claimFor(Transaction $transaction, PlannedTransaction $plan, string $occurrenceDate): ?PlannedPayment
    {
        if ($transaction->user_id !== $plan->user_id) {
            return null;
        }

        $date = $this->parseDate($occurrenceDate);

        return DB::transaction(function () use ($transaction, $plan, $date): ?PlannedPayment {
            [$transactions] = $this->lockRows([$transaction->id], [], planIds: [$plan->id]);

            $user = User::query()->find($plan->user_id);

            if ($transactions->isEmpty() || ! $user instanceof User) {
                return null;
            }

            $occurrence = $this->occurrenceOn($plan, $date);

            if (! $occurrence instanceof PlannedOccurrence) {
                $this->generator->generate($plan, $date, $date);
                $occurrence = $this->occurrenceOn($plan, $date);
            }

            if (! $occurrence instanceof PlannedOccurrence) {
                return $this->claim($transaction, $plan);
            }

            try {
                return $occurrence->status === OccurrenceStatus::Scheduled ? $this->attach($user, $transaction->id, $occurrence->id) : null;
            } catch (ValidationException|ModelNotFoundException) {
                return null;
            }
        });
    }

    /**
     * Keeps a transaction's payments honest after the transaction itself changed: its plan link, amount,
     * currency or direction. Payments that no longer fit are taken back and, while it is still linked to a
     * plan, it claims again. Only a changed link makes a payment on another plan unfit: a transaction applied
     * by hand without a link keeps its payments until its amount, currency or direction stops fitting them.
     */
    public function revalidate(Transaction $transaction, bool $linkChanged): void
    {
        DB::transaction(function () use ($transaction, $linkChanged): void {
            $this->lockRows([$transaction->id], [], planIds: $transaction->planned_transaction_id === null ? [] : [$transaction->planned_transaction_id]);

            $payments = PlannedPayment::query()->where('transaction_id', $transaction->id)->with('occurrence.plannedTransaction.account')->get();

            if ($payments->isEmpty()) {
                return;
            }

            $fits = $payments->sum('amount') <= abs($transaction->amount)
                && $payments->every(static fn (PlannedPayment $payment): bool => (! $linkChanged || $payment->occurrence->plannedTransaction->id === $transaction->planned_transaction_id)
                    && $payment->occurrence->plannedTransaction->account->currency === $transaction->currency
                    && $payment->occurrence->plannedTransaction->direction === $transaction->direction);

            if ($fits) {
                return;
            }

            $this->release($transaction);

            $plan = $transaction->planned_transaction_id === null
                ? null
                : PlannedTransaction::query()->where('user_id', $transaction->user_id)->find($transaction->planned_transaction_id);

            if ($plan instanceof PlannedTransaction) {
                $this->claim($transaction, $plan);
            }
        });
    }

    /**
     * Moves every payment of one transaction version to another (an edit creates a new version, deleting
     * it hands them back).
     */
    public function carryPayments(int $fromTransactionId, int $toTransactionId): void
    {
        DB::transaction(function () use ($fromTransactionId, $toTransactionId): void {
            $this->lockRows([$fromTransactionId, $toTransactionId], []);

            PlannedPayment::query()->where('transaction_id', $fromTransactionId)->update(['transaction_id' => $toTransactionId]);
        });
    }

    /**
     * Takes back a transaction's payments, except those on the plan it is still linked to, because it was
     * unlinked, moved to another plan or deleted. Automatic closes they caused are undone.
     */
    public function release(Transaction $transaction, ?int $keepPlanId = null): void
    {
        DB::transaction(function () use ($transaction, $keepPlanId): void {
            $this->lockRows([$transaction->id], []);

            $payments = PlannedPayment::query()
                ->where('transaction_id', $transaction->id)
                ->when($keepPlanId !== null, fn ($query) => $query->whereHas('occurrence', fn ($occurrence) => $occurrence->where('planned_transaction_id', '!=', $keepPlanId)))
                ->get();

            if ($payments->isEmpty()) {
                return;
            }

            [, $occurrences] = $this->lockRows([], $payments->pluck('planned_occurrence_id')->all());

            PlannedPayment::query()->whereKey($payments->modelKeys())->delete();

            foreach ($occurrences as $occurrence) {
                $this->reopenIfDisqualified($occurrence);
            }
        });
    }

    private function closeIfSettled(PlannedOccurrence $occurrence): bool
    {
        if (! $this->settles($occurrence)) {
            return false;
        }

        $occurrence->update([
            'status' => OccurrenceStatus::Paid,
            'closed_at' => now(),
            'closed_manually' => false,
        ]);

        return true;
    }

    private function reopenIfDisqualified(PlannedOccurrence $occurrence): void
    {
        if ($occurrence->status !== OccurrenceStatus::Paid || $occurrence->closed_manually || $this->settles($occurrence)) {
            return;
        }

        $occurrence->update([
            'status' => OccurrenceStatus::Scheduled,
            'closed_at' => null,
        ]);
    }

    /**
     * The payments reach what the occurrence expects (within a cent), or one payment lands within the
     * plan's tolerance of it.
     */
    private function settles(PlannedOccurrence $occurrence): bool
    {
        $expected = $this->expected($occurrence);
        $amounts = PlannedPayment::query()->where('planned_occurrence_id', $occurrence->id)->pluck('amount');

        if ($amounts->isEmpty() || $expected <= 0) {
            return false;
        }

        if ($amounts->sum() >= $expected - self::SETTLE_SLACK_CENTS) {
            return true;
        }

        return $amounts->count() === 1
            && abs($amounts->first() - $expected) <= $expected * $occurrence->plannedTransaction->amount_tolerance_bps / 10000;
    }

    private function expected(PlannedOccurrence $occurrence): int
    {
        return abs($occurrence->effectiveExpectedAmount());
    }

    private function paid(PlannedOccurrence $occurrence): int
    {
        return (int) PlannedPayment::query()->where('planned_occurrence_id', $occurrence->id)->sum('amount');
    }

    private function learn(PlannedTransaction $plan, Transaction $transaction, PlannedOccurrence $occurrence): void
    {
        $changes = [];
        $aliases = $plan->learned_aliases ?? [];
        $descriptions = $aliases['descriptions'] ?? [];
        $keys = $aliases['merchant_keys'] ?? [];
        $description = mb_substr(mb_trim((string) $transaction->description), 0, 255);

        if ($description !== '' && mb_strtolower($description) !== mb_strtolower($plan->description)
            && ! in_array(mb_strtolower($description), array_map(mb_strtolower(...), $descriptions), true) && count($descriptions) < self::MAX_ALIASES) {
            $descriptions[] = $description;
        }

        $key = $transaction->merchant_key;

        if (filled($key) && $key !== $plan->merchant_key && ! in_array($key, $keys, true) && count($keys) < self::MAX_ALIASES) {
            $keys[] = $key;
        }

        if ($descriptions !== ($aliases['descriptions'] ?? []) || $keys !== ($aliases['merchant_keys'] ?? [])) {
            $changes['learned_aliases'] = ['descriptions' => $descriptions, 'merchant_keys' => $keys];
        }

        $payments = PlannedPayment::query()->where('planned_occurrence_id', $occurrence->id)->pluck('amount');
        $expected = $this->expected($occurrence);

        if ($payments->count() === 1 && $expected > 0 && $payments->first() > $expected + self::SETTLE_SLACK_CENTS) {
            $deviationBps = (int) ceil(($payments->first() - $expected) / $expected * 10000);
            $widened = min(self::MAX_TOLERANCE_BPS, $deviationBps);

            if ($widened > $plan->amount_tolerance_bps) {
                $changes['amount_tolerance_bps'] = $widened;
            }
        }

        if ($changes !== []) {
            $plan->update($changes);
        }
    }

    private function occurrenceOn(PlannedTransaction $plan, CarbonImmutable $date): ?PlannedOccurrence
    {
        return PlannedOccurrence::query()
            ->where('planned_transaction_id', $plan->id)
            ->where(static fn ($query) => $query
                ->whereDate('snoozed_until', $date)
                ->orWhere(static fn ($unsnoozed) => $unsnoozed->whereNull('snoozed_until')->whereDate('due_date', $date)))
            ->first();
    }

    private function lockOccurrence(User $user, int $occurrenceId): PlannedOccurrence
    {
        [, $occurrences] = $this->lockRows([], [$occurrenceId], $user);

        return $occurrences->first() ?? throw (new ModelNotFoundException)->setModel(PlannedOccurrence::class);
    }

    /**
     * The one lock order of every payment write: the transaction rows first, in ascending id, then the plan
     * rows (a write that creates occurrences or learns from a payment changes the plan), then the occurrence
     * rows, in ascending id. Nothing else in the payment code locks rows, so two writers that
     * touch the same transaction and occurrence always queue in the same direction and cannot deadlock.
     * With $user the rows must be that user's; with $live the transactions must be current, undeleted
     * versions, otherwise deleted ones are locked too (a delete is releasing their payments).
     *
     * @param  list<int>  $transactionIds
     * @param  list<int>  $occurrenceIds
     * @param  list<int>  $planIds
     * @return array{0: Collection<int, Transaction>, 1: Collection<int, PlannedOccurrence>}
     */
    private function lockRows(array $transactionIds, array $occurrenceIds, ?User $user = null, bool $live = false, array $planIds = []): array
    {
        $transactions = $transactionIds === []
            ? new Collection
            : ($live ? Transaction::query()->current() : Transaction::withTrashed())
                ->when($user !== null, fn ($query) => $query->where('user_id', $user->id))
                ->whereKey(array_values(array_unique($transactionIds)))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

        if ($planIds !== []) {
            PlannedTransaction::query()->whereKey(array_values(array_unique($planIds)))->orderBy('id')->lockForUpdate()->get();
        }

        $occurrences = $occurrenceIds === []
            ? new Collection
            : PlannedOccurrence::query()
                ->when($user !== null, fn ($query) => $query->forUser($user))
                ->whereKey(array_values(array_unique($occurrenceIds)))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->load('plannedTransaction.account');

        return [$transactions, $occurrences];
    }

    private function requireOpen(PlannedOccurrence $occurrence): void
    {
        if ($occurrence->status !== OccurrenceStatus::Scheduled) {
            $this->refuse(__('Only an open occurrence can take a payment.'));
        }
    }

    private function parseDate(string $date): CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (InvalidFormatException) {
            $parsed = null;
        }

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $date) {
            $this->refuse(__('That date could not be read.'));
        }

        return $parsed;
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['payment' => $message]);
    }
}
