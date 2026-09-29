<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Exceptions\TransferLinkRefusedException;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transfers\TransferLinker;
use App\Services\Transfers\TransferReviewQueue;
use App\Services\Transfers\UntrackedAccountCreator;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

final class TransferReview extends Component
{
    /** The unlinked row the "link to hidden account" panel is open for. */
    #[Locked]
    public ?int $linkingId = null;

    /** Existing hidden account chosen in the panel; empty means "create a new one". */
    public string $hiddenAccountId = '';

    public string $newAccountName = '';

    /**
     * Every action resolves its row through a user-scoped query, so a forged id
     * from another user's data resolves to nothing (404) rather than being acted on.
     *
     * @throws Throwable
     */
    public function confirm(int $transactionId, TransferLinker $linker): void
    {
        if (! $linker->confirm($this->ownedTransaction($transactionId))) {
            Flux::toast(text: 'That pair changed while you were reviewing it. Nothing was confirmed.', variant: 'danger');

            return;
        }

        $this->dispatch('transfer-rules-changed');

        Flux::toast(text: 'Transfer confirmed', variant: 'success');
    }

    /** @throws Throwable */
    public function notTransfer(int $transactionId, TransferLinker $linker): void
    {
        if (! $linker->markNotTransfer($this->ownedQueueTransaction($transactionId))) {
            Flux::toast(text: 'That row changed while you were reviewing it. Nothing was changed.', variant: 'danger');

            return;
        }

        Flux::toast(text: 'Marked as not a transfer', variant: 'success');
    }

    public function startLinkHidden(int $transactionId): void
    {
        $this->ownedUnlinkedTransaction($transactionId);

        $this->linkingId = $transactionId;
        $this->hiddenAccountId = '';
        $this->newAccountName = '';
        $this->resetErrorBag();
    }

    public function cancelLinkHidden(): void
    {
        $this->reset('linkingId', 'hiddenAccountId', 'newAccountName');
        $this->resetErrorBag();
    }

    /** @throws Throwable */
    public function linkToHidden(TransferLinker $linker, UntrackedAccountCreator $creator): void
    {
        abort_if($this->linkingId === null, 404);

        $user = $this->authenticatedUser();
        $transaction = $this->ownedUnlinkedTransaction($this->linkingId);

        if ($this->hiddenAccountId === '') {
            $this->validate(['newAccountName' => ['required', 'string', 'max:100']]);
        }

        // Creating the hidden account and linking are one unit: a row claimed elsewhere in the
        // meantime rolls the new account back instead of leaving an orphan.
        try {
            $account = DB::transaction(function () use ($linker, $creator, $user, $transaction): Account {
                $account = $this->hiddenAccountId !== ''
                    ? Account::query()
                        ->where('user_id', $user->id)
                        ->where('is_tracked', false)
                        ->findOrFail((int) $this->hiddenAccountId)
                    : $creator->create($user, mb_trim($this->newAccountName));

                $linker->linkToHiddenAccount($transaction, $account);

                return $account;
            });
        } catch (TransferLinkRefusedException $e) {
            $this->addError('hiddenAccountId', $e->getMessage());

            return;
        }

        $this->cancelLinkHidden();
        $this->dispatch('transfer-rules-changed');

        Flux::toast(text: "Linked to {$account->name}", variant: 'success');
    }

    public function render(): View
    {
        $user = $this->authenticatedUser();

        $queue = app(TransferReviewQueue::class);

        $debitLegs = $queue->suggestedPairs($user)
            ->with('account:id,name')
            ->orderByDesc('post_date')
            ->orderByDesc('id')
            ->get();

        $creditLegs = Transaction::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $debitLegs->pluck('suggested_pair_id'))
            ->with('account:id,name')
            ->get()
            ->keyBy('id');

        /** @var Collection<int, array{debit: Transaction, credit: Transaction}> $pairs */
        $pairs = $debitLegs
            ->filter(fn (Transaction $debit): bool => $creditLegs->has($debit->suggested_pair_id))
            ->map(fn (Transaction $debit): array => ['debit' => $debit, 'credit' => $creditLegs->get($debit->suggested_pair_id)])
            ->values();

        $unmatched = $queue->unmatched($user)
            ->with('account:id,name')
            ->orderByDesc('post_date')
            ->orderByDesc('id')
            ->get();

        return view('livewire.transfer-review', [
            'pairs' => $pairs,
            'unmatched' => $unmatched,
            'hiddenAccounts' => Account::query()
                ->where('user_id', $user->id)
                ->where('is_tracked', false)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    private function authenticatedUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * "Not a transfer" only applies to what the queue shows: a pending suggestion or an
     * unmatched row. A forged id for any other row (manual, CSV, plain feed, linked) is a 404.
     */
    private function ownedQueueTransaction(int $id): Transaction
    {
        $user = $this->authenticatedUser();

        $suggested = Transaction::query()
            ->where('user_id', $user->id)
            ->current()
            ->possibleTransfer()
            ->find($id);

        if ($suggested instanceof Transaction) {
            return $suggested;
        }

        return app(TransferReviewQueue::class)->unmatched($user)->findOrFail($id);
    }

    private function ownedTransaction(int $id): Transaction
    {
        return Transaction::query()
            ->where('user_id', $this->authenticatedUser()->id)
            ->current()
            ->findOrFail($id);
    }

    /**
     * Only rows that are actually in the unmatched review queue may be linked to a hidden
     * account; a forged id for any other row (manual, CSV, already linked) resolves to nothing.
     */
    private function ownedUnlinkedTransaction(int $id): Transaction
    {
        return app(TransferReviewQueue::class)
            ->unmatched($this->authenticatedUser())
            ->findOrFail($id);
    }
}
