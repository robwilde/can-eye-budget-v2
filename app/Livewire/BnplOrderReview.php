<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Enums\BnplOrderEventType;
use App\Enums\BnplOrderStatus;
use App\Models\BnplOrder;
use App\Models\Category;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

final class BnplOrderReview extends Component
{
    /** @var array<int, int|string|null> */
    public array $categories = [];

    /** @return Collection<array-key, Collection<int, BnplOrder>> */
    #[Computed]
    public function pendingOrders(): Collection
    {
        return BnplOrder::query()
            ->where('user_id', Auth::id())
            ->where('status', BnplOrderStatus::PendingReview)
            ->with(['category', 'plannedTransaction'])
            ->orderBy('retailer')
            ->orderByDesc('id')
            ->get()
            ->toBase()
            ->groupBy('retailer');
    }

    /** @return Collection<int, BnplOrder> */
    #[Computed]
    public function recentlyAutoApproved(): Collection
    {
        return BnplOrder::query()
            ->where('user_id', Auth::id())
            ->where('status', BnplOrderStatus::AutoApproved)
            ->where('reviewed_at', '>=', now()->subDays(30))
            ->with(['category', 'plannedTransaction'])
            ->orderBy('retailer')
            ->orderByDesc('id')
            ->get()
            ->toBase();
    }

    public function approve(int $orderId): void
    {
        $order = $this->findOrder($orderId, BnplOrderStatus::PendingReview);

        if ($order->plannedTransaction === null && ! $order->isSettled()) {
            $this->addError("categories.$orderId", __('This order has no supported repayment schedule yet, so it cannot be approved.'));

            return;
        }

        $categoryId = $this->validCategoryId($orderId);

        if ($categoryId === null) {
            return;
        }

        $order->getConnection()->transaction(function () use ($order, $categoryId): void {
            $order->update([
                'category_id' => $categoryId,
                'status' => BnplOrderStatus::Approved,
                'reviewed_at' => now(),
            ]);
            $order->plannedTransaction?->update(['category_id' => $categoryId]);
            $order->recordEvent(BnplOrderEventType::CategorySet, ['category_id' => $categoryId], (string) Auth::id());
            $order->recordEvent(BnplOrderEventType::Approved, [], (string) Auth::id());
        });

        $this->refreshLists();
    }

    public function reject(int $orderId): void
    {
        $order = $this->findOrder($orderId, BnplOrderStatus::PendingReview);

        $order->getConnection()->transaction(function () use ($order): void {
            $order->update([
                'status' => BnplOrderStatus::Rejected,
                'reviewed_at' => now(),
            ]);
            $order->plannedTransaction?->update(['is_active' => false]);
            $order->recordEvent(BnplOrderEventType::Rejected, [], (string) Auth::id());
        });

        $this->refreshLists();
    }

    public function recategorise(int $orderId): void
    {
        $order = $this->findOrder($orderId, BnplOrderStatus::AutoApproved);
        $categoryId = $this->validCategoryId($orderId);

        if ($categoryId === null) {
            return;
        }

        $order->getConnection()->transaction(function () use ($order, $categoryId): void {
            $order->update([
                'category_id' => $categoryId,
                'reviewed_at' => now(),
            ]);
            $order->plannedTransaction?->update(['category_id' => $categoryId]);
            $order->recordEvent(BnplOrderEventType::CategorySet, ['category_id' => $categoryId], (string) Auth::id());
        });

        $this->refreshLists();
    }

    public function render(): View
    {
        $enabled = (bool) config('budget.bnpl_email_import');

        if ($enabled) {
            $orders = $this->pendingOrders->flatten(1) // @phpstan-ignore property.notFound
                ->concat($this->recentlyAutoApproved); // @phpstan-ignore property.notFound

            foreach ($orders as $order) {
                $this->categories[$order->id] ??= $order->category_id;
            }
        }

        return view('livewire.bnpl-order-review', [
            'enabled' => $enabled,
            'formatMoney' => MoneyCast::format(...),
            'categoryOptions' => $enabled ? Category::visibleSortedByFullPath() : [],
        ]);
    }

    private function findOrder(int $orderId, BnplOrderStatus $status): BnplOrder
    {
        abort_unless(config('budget.bnpl_email_import'), 404);

        $order = BnplOrder::query()
            ->where('user_id', Auth::id())
            ->where('status', $status)
            ->with('plannedTransaction')
            ->find($orderId);

        abort_if($order === null, 404);

        return $order;
    }

    private function validCategoryId(int $orderId): ?int
    {
        $categoryId = $this->categories[$orderId] ?? null;

        if ($categoryId === null || $categoryId === '' || ! Category::visible()->whereKey($categoryId)->exists()) {
            $this->addError("categories.$orderId", __('Pick a category first.'));

            return null;
        }

        return (int) $categoryId;
    }

    private function refreshLists(): void
    {
        unset($this->pendingOrders, $this->recentlyAutoApproved);
        $this->resetErrorBag();
        $this->dispatch('bnpl-orders-reviewed');
    }
}
