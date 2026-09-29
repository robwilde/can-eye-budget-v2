<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransferLinkSource;
use App\Exceptions\TransferLinkRefusedException;
use App\Models\Transaction;
use App\Models\TransferRule;
use App\Models\User;
use App\Support\Transfers\TransferSignal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

final readonly class TransferDetector
{
    public function __construct(private TransferLinker $linker) {}

    /**
     * Rules first (a remembered transfer links automatically), then strict suggestions.
     *
     * @return array{rule: int, suggested: int, pairs: list<array{debit: int, credit: int, source: string}>}
     *
     * @throws Throwable
     */
    public function run(User $user, bool $dryRun = false): array
    {
        $result = $this->runRules($user, $dryRun);
        $pairs = $result['pairs'];
        $rule = $result['rule'];

        // A dry run persists nothing, so rows a rule already claimed still look linkable:
        // keep them out of the suggestion pass or the preview would count a pair twice.
        $claimed = collect($pairs)->flatMap(fn (array $p): array => [$p['debit'], $p['credit']])->filter()->all();

        foreach ($this->suggest($user, $dryRun, $claimed) as $pair) {
            $pairs[] = $pair + ['source' => TransferLinkSource::Suggested->value];
        }

        return ['rule' => $rule, 'suggested' => count($pairs) - $rule, 'pairs' => $pairs];
    }

    /**
     * Only the remembered-rule pass (no suggestions): the pipeline runs this before the user's
     * category rules have assigned Transfer categories, when the suggestion signals are incomplete.
     *
     * @return array{rule: int, suggested: int, pairs: list<array{debit: int, credit: int, source: string}>}
     *
     * @throws Throwable
     */
    public function runRules(User $user, bool $dryRun = false): array
    {
        $pairs = [];

        foreach ($this->applyRules($user, $dryRun) as $pair) {
            $pairs[] = $pair + ['source' => TransferLinkSource::Rule->value];
        }

        return ['rule' => count($pairs), 'suggested' => 0, 'pairs' => $pairs];
    }

    /**
     * @return list<array{debit: int, credit: int}>
     *
     * @throws Throwable
     */
    private function applyRules(User $user, bool $dryRun): array
    {
        $rules = TransferRule::query()->where('user_id', $user->id)->with('counterpartAccount')->get();

        if ($rules->isEmpty()) {
            return [];
        }

        $pairs = [];
        $claimed = [];

        // Sweep until nothing more links: a row skipped because a competitor was still
        // unlinked is re-judged once that competitor is claimed, so a second run finds nothing new.
        do {
            $progress = false;

            foreach ($this->ruleRows($user, $rules) as $row) {
                if (isset($claimed[$row->id])) {
                    continue;
                }

                $matching = $rules->filter(fn (TransferRule $r): bool => $r->matches($row));

                // Overlapping rules that point at different counterparts are ambiguous: leave
                // the row for the user instead of auto-linking to whichever rule loaded first.
                if ($matching->isEmpty() || $matching->pluck('counterpart_account_id')->unique()->count() > 1) {
                    continue;
                }

                /** @var TransferRule $rule */
                $rule = $matching->first();

                // A hidden counterpart has no imported row to pair with: the rule
                // materialises the mirror leg instead. linkable() already excluded
                // rows that are linked or were rejected, so this is idempotent.
                if ($rule->counterpartAccount !== null && ! $rule->counterpartAccount->is_tracked) {
                    // Real rows win, and the preview must agree with the real run: rows a rule
                    // claimed earlier in this pass are already linked in a real run, so they
                    // are not candidates here either.
                    $real = $this->linker->candidatesFor($row)
                        ->reject(fn (Transaction $c): bool => isset($claimed[$c->id]));

                    if ($real->isNotEmpty()) {
                        continue;
                    }

                    $mirrorId = 0;

                    if (! $dryRun) {
                        try {
                            $mirrorId = $this->linker->linkToUntrackedAccount($row, $rule->counterpartAccount, TransferLinkSource::Rule)->id;
                        } catch (TransferLinkRefusedException) {
                            continue;
                        }
                    }

                    $claimed[$row->id] = true;
                    $progress = true;

                    $pairs[] = $row->direction === TransactionDirection::Debit
                        ? ['debit' => $row->id, 'credit' => $mirrorId]
                        : ['debit' => $mirrorId, 'credit' => $row->id];

                    continue;
                }

                // The single-match check spans every tracked account, not just the rule's
                // counterpart: a second possible match anywhere makes the row ambiguous.
                $candidates = $this->linker->candidatesFor($row)
                    ->reject(fn (Transaction $c): bool => isset($claimed[$c->id]));

                if ($candidates->count() !== 1) {
                    continue;
                }

                /** @var Transaction $other */
                $other = $candidates->first();

                if ($other->account_id !== $rule->counterpart_account_id || ! $this->isImported($other)) {
                    continue;
                }

                // Mutual: the counterpart row must have exactly one candidate too, this row,
                // across all tracked accounts.
                $reverse = $this->linker->candidatesFor($other)
                    ->reject(fn (Transaction $c): bool => isset($claimed[$c->id]));

                if ($reverse->count() !== 1 || $reverse->first()->id !== $row->id) {
                    continue;
                }

                if (! $dryRun && ! $this->linker->link($row, $other, TransferLinkSource::Rule)) {
                    continue;
                }

                $claimed[$row->id] = $claimed[$other->id] = true;
                $progress = true;
                $pairs[] = $this->pair($row, $other);
            }
        } while ($progress);

        return $pairs;
    }

    /**
     * A pair is suggested only when each row is the other's single candidate; anything
     * ambiguous (e.g. repeated Round Ups) is left alone and never tie-broken.
     *
     * Only rows with a transfer signal (description mentions "transfer" or the row is
     * categorised under Transfer) are considered, on both legs.
     *
     * @param  list<int>  $claimed  Rows a rule already paired in this run
     * @return list<array{debit: int, credit: int}>
     *
     * @throws Throwable
     */
    private function suggest(User $user, bool $dryRun, array $claimed = []): array
    {
        // Rows already carrying a pending suggestion stay in the graph: they still compete as
        // matches (a later Round Up must not look unique because its rival is pending), but
        // a pair is never emitted for a leg that is already suggested, so a re-run is a no-op.
        $rows = $this->importedLinkable($user)
            ->tap(TransferSignal::whereCandidate(...))
            ->get()
            ->reject(fn (Transaction $t): bool => in_array($t->id, $claimed, true))
            ->filter($this->isImported(...))
            ->filter(TransferSignal::isCandidate(...));

        $debits = $rows->where('direction', TransactionDirection::Debit);
        $creditsByAmount = $rows->where('direction', TransactionDirection::Credit)->groupBy(fn (Transaction $t): int => abs($t->amount));

        /** @var array<int, list<Transaction>> $debitsFor credit id => matching debits */
        $debitsFor = [];
        /** @var array<int, list<Transaction>> $creditsFor debit id => matching credits */
        $creditsFor = [];

        foreach ($debits as $debit) {
            foreach ($creditsByAmount->get(abs($debit->amount), []) as $credit) {
                if ($credit->account_id === $debit->account_id) {
                    continue;
                }

                if (abs($credit->post_date->diffInDays($debit->post_date)) > TransferLinker::WINDOW_DAYS) {
                    continue;
                }

                $creditsFor[$debit->id][] = $credit;
                $debitsFor[$credit->id][] = $debit;
            }
        }

        $pairs = [];

        foreach ($debits as $debit) {
            $credits = $creditsFor[$debit->id] ?? [];

            if (count($credits) !== 1 || count($debitsFor[$credits[0]->id] ?? []) !== 1) {
                continue;
            }

            if ($debit->suggested_pair_id !== null || $credits[0]->suggested_pair_id !== null) {
                continue;
            }

            if (! $dryRun && ! $this->linker->suggest($debit, $credits[0])) {
                continue;
            }

            $pairs[] = $this->pair($debit, $credits[0]);
        }

        return $pairs;
    }

    /**
     * Bank-feed, still-linkable rows of the user. The source filter is in SQL so manual, planned
     * and CSV rows are never loaded.
     *
     * @return Builder<Transaction>
     */
    private function importedLinkable(User $user): Builder
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->current()
            ->linkable()
            ->whereIn('source', array_map(fn (TransactionSource $s): string => $s->value, TransactionSource::bankFeed()))
            ->with('category.parent')
            ->orderBy('id');
    }

    /**
     * Only rows that can match some rule: same account and a description containing the
     * pattern. The LIKE is a superset (wildcards in a pattern only widen it); matches() decides.
     *
     * @param  Collection<int, TransferRule>  $rules
     * @return Collection<int, Transaction>
     */
    private function ruleRows(User $user, Collection $rules): Collection
    {
        return $this->importedLinkable($user)
            ->where(function (Builder $q) use ($rules): void {
                foreach ($rules as $rule) {
                    $q->orWhere(fn (Builder $r): Builder => $r
                        ->where('account_id', $rule->account_id)
                        ->where('description', 'like', '%'.$rule->description_pattern.'%'));
                }
            })
            ->get();
    }

    private function isImported(Transaction $transaction): bool
    {
        return in_array($transaction->source, TransactionSource::bankFeed(), true);
    }

    /** @return array{debit: int, credit: int} */
    private function pair(Transaction $a, Transaction $b): array
    {
        return $a->direction === TransactionDirection::Debit
            ? ['debit' => $a->id, 'credit' => $b->id]
            : ['debit' => $b->id, 'credit' => $a->id];
    }
}
