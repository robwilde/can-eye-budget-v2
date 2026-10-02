<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\BnplOrderEventType;
use App\Enums\BnplOrderStatus;
use App\Events\PlannedTransactionCategoryUpdated;
use App\Models\BnplOrder;

/**
 * Copies a BNPL plan's category back to its order. The order is the
 * retailer's category memory, so setting the category once on the plan
 * categorises the next order from that retailer automatically. Setting it
 * approves an order that only awaited a category; clearing it sends an
 * approved order back to review.
 */
final class RememberBnplCategory
{
    public function handle(PlannedTransactionCategoryUpdated $event): void
    {
        $plan = $event->plannedTransaction;

        $order = BnplOrder::query()->where('planned_transaction_id', $plan->id)->first();

        if ($order === null || $order->category_id === $plan->category_id) {
            return;
        }

        $previousCategoryId = $order->category_id;
        $status = match (true) {
            $plan->category_id === null && $order->status->isApproved() => BnplOrderStatus::PendingReview,
            $plan->category_id !== null && $this->awaitsCategory($order) => BnplOrderStatus::Approved,
            default => $order->status,
        };
        $statusChanged = $status !== $order->status;

        $order->update([
            'category_id' => $plan->category_id,
            'reviewed_at' => $status === BnplOrderStatus::PendingReview ? null : now(),
            ...($statusChanged ? [
                'status' => $status,
                'review_note' => $status === BnplOrderStatus::PendingReview ? BnplOrder::REVIEW_NOTE_NO_CATEGORY : null,
            ] : []),
        ]);

        $actor = (string) $plan->user_id;

        $order->recordEvent(BnplOrderEventType::CategorySet, [
            'category_id' => $plan->category_id,
            'previous_category_id' => $previousCategoryId,
        ], $actor);

        if (! $statusChanged) {
            return;
        }

        if ($status === BnplOrderStatus::Approved) {
            $order->recordEvent(BnplOrderEventType::Approved, [], $actor);

            return;
        }

        $order->recordEvent(BnplOrderEventType::ReviewRequested, ['review_note' => BnplOrder::REVIEW_NOTE_NO_CATEGORY], $actor);
    }

    private function awaitsCategory(BnplOrder $order): bool
    {
        return $order->status === BnplOrderStatus::PendingReview
            && $order->review_note === BnplOrder::REVIEW_NOTE_NO_CATEGORY;
    }
}
