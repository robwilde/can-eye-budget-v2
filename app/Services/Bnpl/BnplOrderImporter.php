<?php

declare(strict_types=1);

namespace App\Services\Bnpl;

use App\DTOs\ParsedSchedule;
use App\DTOs\RawEmail;
use App\Enums\BnplOrderEventType;
use App\Enums\BnplOrderStatus;
use App\Enums\BnplProvider;
use App\Enums\TransactionDirection;
use App\Models\BnplOrder;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\GmailService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Turns a parsed BNPL schedule into a bnpl_orders row and, while instalments
 * remain, one planned transaction that replays them. The first email seen for
 * an order decides everything; later emails for the same order change
 * nothing here (they are linked to postings instead).
 */
final readonly class BnplOrderImporter
{
    public function __construct(private BnplAccountResolver $accounts) {}

    /**
     * @return BnplOrder the existing order for this provider reference or
     *                   message, else the newly created one
     */
    public function import(User $user, RawEmail $email, ParsedSchedule $schedule): BnplOrder
    {
        return $user->getConnection()->transaction(function () use ($user, $email, $schedule): BnplOrder {
            $existing = BnplOrder::query()
                ->where('user_id', $user->id)
                ->where(fn (Builder $query): Builder => $query
                    ->where(fn (Builder $ref): Builder => $ref
                        ->where('provider', $schedule->provider)
                        ->where('order_ref', $schedule->orderRef))
                    ->orWhere('gmail_message_id', $email->messageId))
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $order = $this->createOrder($user, $email, $schedule);

            if ($order->frequency !== null && ! $order->isSettled()) {
                $this->createPlan($order);
            }

            return $order;
        });
    }

    private function createOrder(User $user, RawEmail $email, ParsedSchedule $schedule): BnplOrder
    {
        $frequency = $schedule->frequency();
        $categoryId = $this->rememberedCategory($user, $schedule);

        [$status, $reviewNote] = match (true) {
            $frequency === null => [BnplOrderStatus::PendingReview, BnplOrder::REVIEW_NOTE_UNSUPPORTED_CADENCE],
            $categoryId === null => [BnplOrderStatus::PendingReview, BnplOrder::REVIEW_NOTE_NO_CATEGORY],
            default => [BnplOrderStatus::AutoApproved, null],
        };

        $order = BnplOrder::query()->create([
            'user_id' => $user->id,
            'provider' => $schedule->provider,
            'retailer' => $schedule->retailer,
            'order_ref' => $schedule->orderRef,
            'total' => $schedule->total,
            'instalment_amount' => $schedule->instalmentAmount(),
            'instalment_count' => count($schedule->instalments),
            'first_due_date' => $schedule->firstDueDate(),
            'last_due_date' => $schedule->lastDueDate(),
            'frequency' => $frequency,
            'account_id' => $this->accounts->resolve($user, $schedule->provider),
            'category_id' => $categoryId,
            'card_last4' => $schedule->cardLast4,
            'status' => $status,
            'review_note' => $reviewNote,
            'gmail_message_id' => $email->messageId,
            'subject' => $email->subject,
            'email_date' => $email->date,
            'snippet' => GmailService::snippetFromBodies($email->textBody, $email->htmlBody),
            'gmail_url' => GmailService::deepLink($email->messageId),
            'parsed_payload' => $schedule->toArray(),
            'reviewed_at' => $status === BnplOrderStatus::AutoApproved ? now() : null,
        ]);

        $order->recordEvent(BnplOrderEventType::EmailPulled, ['message_id' => $email->messageId]);

        if ($status === BnplOrderStatus::AutoApproved) {
            $order->recordEvent(BnplOrderEventType::AutoApproved, ['category_id' => $categoryId]);
        } else {
            $order->recordEvent(BnplOrderEventType::ReviewRequested, ['review_note' => $reviewNote]);
        }

        if ($frequency === null) {
            Log::warning('BNPL schedule has no supported cadence; no plan created', [
                'bnpl_order_id' => $order->id,
                'instalments' => $schedule->toArray()['instalments'],
            ]);
        }

        return $order;
    }

    /**
     * Same payload shape as a plan created in the transaction modal.
     */
    private function createPlan(BnplOrder $order): void
    {
        $plan = PlannedTransaction::query()->create([
            'user_id' => $order->user_id,
            'account_id' => $order->account_id,
            'transfer_to_account_id' => null,
            'category_id' => $order->category_id,
            'amount' => $order->instalment_amount,
            'direction' => TransactionDirection::Debit,
            'description' => $this->planDescription($order),
            'start_date' => $order->first_due_date,
            'frequency' => $order->frequency,
            'until_date' => $order->last_due_date,
            'is_active' => true,
        ]);

        $order->update(['planned_transaction_id' => $plan->id]);
        $order->recordEvent(BnplOrderEventType::PlanCreated, ['planned_transaction_id' => $plan->id]);
    }

    private function planDescription(BnplOrder $order): string
    {
        return match ($order->provider) {
            BnplProvider::Paypal => 'PayPal Pay in 4 - '.$order->retailer,
            default => $order->provider->label().' - '.$order->retailer,
        };
    }

    /**
     * The category the user last gave an order from this retailer, so a repeat
     * purchase is categorised without asking again.
     */
    private function rememberedCategory(User $user, ParsedSchedule $schedule): ?int
    {
        $categoryId = BnplOrder::query()
            ->where('user_id', $user->id)
            ->where('provider', $schedule->provider)
            ->where('retailer', $schedule->retailer)
            ->whereNotNull('category_id')
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->value('category_id');

        return $categoryId === null ? null : (int) $categoryId;
    }
}
