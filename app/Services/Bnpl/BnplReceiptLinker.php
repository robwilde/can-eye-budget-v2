<?php

declare(strict_types=1);

namespace App\Services\Bnpl;

use App\DTOs\ParsedSchedule;
use App\DTOs\RawEmail;
use App\DTOs\ScheduleInstalment;
use App\Enums\BnplOrderEventType;
use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Models\BnplOrder;
use App\Models\Transaction;
use App\Models\TransactionEmail;
use App\Services\ReconciliationMatcher;
use App\Services\ReconciliationPolicy;
use App\Services\TransactionEmailLinker;
use App\Support\Email\ReceiptParser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Pairs a BNPL payment receipt with the bank posting it describes. The bank
 * line carries no plan identifier, but the receipt names the loan, the exact
 * amount charged and the day it was taken, which is enough to pick the posting
 * deterministically: the email is attached to it, and the posting is linked to
 * the order's plan, overriding any tolerance-based guess.
 */
final readonly class BnplReceiptLinker
{
    public function __construct(
        private ReconciliationMatcher $matcher,
        private TransactionEmailLinker $emails,
    ) {}

    public function link(BnplOrder $order, RawEmail $email, ParsedSchedule $schedule): BnplLinkResult
    {
        $payment = $schedule->payment;
        $pattern = $order->provider->bankDescriptionPattern();

        if ($payment === null || $pattern === null || $this->alreadyAttached($order, $email)) {
            return new BnplLinkResult(null);
        }

        $candidates = $this->nearestCandidates($order, $email, $payment, $pattern);

        if ($candidates->count() > 1) {
            Log::info('BNPL receipt ambiguous', [
                'bnpl_order_id' => $order->id,
                'gmail_message_id' => $email->messageId,
                'candidate_ids' => $candidates->modelKeys(),
            ]);

            return new BnplLinkResult(null, ambiguous: true);
        }

        $transaction = $candidates->first();

        if ($transaction === null) {
            return new BnplLinkResult(null);
        }

        $order->getConnection()->transaction(fn () => $this->attach($order, $email, $payment, $transaction));

        return new BnplLinkResult($transaction);
    }

    /**
     * A receipt is attached once per user: re-reading it on a later night (or
     * after the user attached it by hand) changes nothing.
     */
    private function alreadyAttached(BnplOrder $order, RawEmail $email): bool
    {
        return TransactionEmail::query()
            ->where('user_id', $order->user_id)
            ->where('gmail_message_id', $email->messageId)
            ->exists();
    }

    /**
     * Posted debits of exactly the receipt amount, described as this
     * provider's instalments, within the reconciliation date tolerance and not
     * already carrying another receipt from the same sender; only those at the
     * smallest day distance survive. Holds are excluded: an expiring hold is
     * deleted, which would silently drop the link.
     *
     * @return Collection<int, Transaction>
     */
    private function nearestCandidates(BnplOrder $order, RawEmail $email, ScheduleInstalment $payment, string $pattern): Collection
    {
        $tolerance = ReconciliationPolicy::DATE_TOLERANCE_DAYS;

        $candidates = Transaction::query()
            ->where('user_id', $order->user_id)
            ->current()
            ->where('status', TransactionStatus::Posted)
            ->where('direction', TransactionDirection::Debit)
            ->whereIn('amount', [$payment->amount, -$payment->amount])
            ->where('description', 'like', $pattern)
            ->whereBetween('post_date', [$payment->date->subDays($tolerance), $payment->date->addDays($tolerance)])
            ->whereDoesntHave('emails', fn (Builder $query): Builder => $query
                ->where('from_address', $email->fromAddress)
                ->where('gmail_message_id', '!=', $email->messageId))
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $distances = $candidates->mapWithKeys(fn (Transaction $transaction): array => [
            $transaction->id => ReconciliationPolicy::dayDiff($transaction->post_date, $payment->date),
        ]);
        $nearest = $distances->min();

        return $candidates
            ->filter(fn (Transaction $transaction): bool => $distances[$transaction->id] === $nearest)
            ->values();
    }

    private function attach(BnplOrder $order, RawEmail $email, ScheduleInstalment $payment, Transaction $transaction): void
    {
        $this->emails->link(
            $transaction,
            $email->toSearchResult(ReceiptParser::parse($email->textBody, $email->htmlBody)),
        );

        $plan = $order->plannedTransaction;
        $relinkedFrom = null;
        $unlinked = [];

        if ($plan !== null) {
            if ($transaction->planned_transaction_id !== $plan->id) {
                $relinkedFrom = $transaction->planned_transaction_id;
                $this->matcher->link($transaction, $plan);
            }

            $unlinked = $this->releaseOccurrence($plan->id, $transaction, $payment);
        }

        $order->recordEvent(BnplOrderEventType::PaymentLinked, [
            'transaction_id' => $transaction->id,
            'gmail_message_id' => $email->messageId,
            'amount' => $payment->amount,
            'posted_on' => $payment->date->toDateString(),
            'planned_transaction_id' => $plan?->id,
            'relinked_from_plan_id' => $relinkedFrom,
            'unlinked_transaction_ids' => $unlinked,
        ]);
    }

    /**
     * Unlink every other posting a tolerance match had attached to this
     * occurrence of the plan, so it is fulfilled once, by the receipt's posting.
     *
     * @return list<int> ids of the unlinked transactions
     */
    private function releaseOccurrence(int $planId, Transaction $transaction, ScheduleInstalment $payment): array
    {
        $tolerance = ReconciliationPolicy::DATE_TOLERANCE_DAYS;

        $others = Transaction::query()
            ->where('planned_transaction_id', $planId)
            ->current()
            ->whereKeyNot($transaction->id)
            ->whereBetween('post_date', [$payment->date->subDays($tolerance), $payment->date->addDays($tolerance)])
            ->orderBy('id')
            ->get();

        foreach ($others as $other) {
            $this->matcher->unlink($other);
        }

        return array_values(array_map('intval', $others->modelKeys()));
    }
}
