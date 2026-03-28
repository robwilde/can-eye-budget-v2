<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Services\ReconciliationMatcher;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class ReconciliationModal extends Component
{
    public bool $showModal = false;

    public ?int $plannedTransactionId = null;

    public ?string $occurrenceDate = null;

    /** @var array{description: string, amount: int, direction: string, category: string|null, account_name: string}|null */
    public ?array $plannedDetails = null;

    /** @var list<array{id: int, description: string, amount: int, direction: string, post_date: string, account_name: string, amount_diff: int}> */
    public array $suggestions = [];

    /** @var array{id: int, description: string, amount: int, post_date: string}|null */
    public ?array $linkedTransaction = null;

    private ReconciliationMatcher $matcher;

    public function boot(ReconciliationMatcher $matcher): void
    {
        $this->matcher = $matcher;
    }

    #[On('open-reconciliation-modal')]
    public function openForPlanned(int $plannedId, string $occurrenceDate): void
    {
        $planned = PlannedTransaction::query()
            ->where('user_id', auth()->id())
            ->with(['category:id,name', 'account:id,name'])
            ->find($plannedId);

        if (! $planned) {
            return;
        }

        $this->plannedTransactionId = $planned->id;
        $this->occurrenceDate = $occurrenceDate;

        $this->plannedDetails = [
            'description' => $planned->description,
            'amount' => $planned->amount,
            'direction' => $planned->direction->value,
            'category' => $planned->category?->name,
            'account_name' => $planned->account->name, // @phpstan-ignore property.nonObject
        ];

        $this->loadMatches($planned, CarbonImmutable::parse($occurrenceDate));
        $this->showModal = true;
    }

    public function link(int $transactionId): void
    {
        $transaction = Transaction::query()
            ->where('user_id', auth()->id())
            ->find($transactionId);

        $planned = PlannedTransaction::query()
            ->where('user_id', auth()->id())
            ->find($this->plannedTransactionId);

        if (! $transaction || ! $planned) {
            return;
        }

        $this->matcher->link($transaction, $planned);

        if ($this->occurrenceDate) {
            $this->loadMatches($planned, CarbonImmutable::parse($this->occurrenceDate));
        }

        $this->dispatch('transaction-saved');
    }

    public function unlink(int $transactionId): void
    {
        $transaction = Transaction::query()
            ->where('user_id', auth()->id())
            ->find($transactionId);

        if (! $transaction) {
            return;
        }

        $this->matcher->unlink($transaction);

        $planned = PlannedTransaction::query()
            ->where('user_id', auth()->id())
            ->find($this->plannedTransactionId);

        if ($planned && $this->occurrenceDate) {
            $this->loadMatches($planned, CarbonImmutable::parse($this->occurrenceDate));
        }

        $this->dispatch('transaction-saved');
    }

    public function editPlanned(): void
    {
        $this->showModal = false;
        $this->dispatch('edit-planned-transaction', id: $this->plannedTransactionId);
    }

    public function render(): View
    {
        return view('livewire.reconciliation-modal', [
            'formatMoney' => MoneyCast::format(...),
        ]);
    }

    private function loadMatches(PlannedTransaction $planned, CarbonImmutable $date): void
    {
        $linked = $this->matcher->findLinkedForOccurrence($planned, $date);

        if ($linked) {
            $this->linkedTransaction = [
                'id' => $linked->id,
                'description' => $linked->clean_description ?? $linked->description,
                'amount' => $linked->amount,
                'post_date' => $linked->post_date->format('Y-m-d'),
            ];
            $this->suggestions = [];

            return;
        }

        $this->linkedTransaction = null;
        $suggestions = $this->matcher->findSuggestions($planned, $date);

        $this->suggestions = $suggestions->map(fn (Transaction $t) => [
            'id' => $t->id,
            'description' => $t->clean_description ?? $t->description,
            'amount' => $t->amount,
            'direction' => $t->direction->value,
            'post_date' => $t->post_date->format('Y-m-d'),
            'account_name' => $t->account->name, // @phpstan-ignore property.nonObject
            'amount_diff' => abs($t->amount) - abs($planned->amount),
        ])->values()->all();
    }
}
