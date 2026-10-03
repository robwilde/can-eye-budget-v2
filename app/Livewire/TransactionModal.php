<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Enums\AccountStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransferLinkSource;
use App\Exceptions\TransferAlreadyLinkedException;
use App\Exceptions\TransferLinkRefusedException;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Services\CategoryRuleGenerator;
use App\Services\TransactionIngestor;
use App\Services\Transfers\TransferLinker;
use App\Support\AmountParser;
use App\Support\AmountParseResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

final class TransactionModal extends Component
{
    public bool $showModal = false;

    public ?int $editingTransactionId = null;

    #[Locked]
    public bool $isBankFeedTransaction = false;

    #[Locked]
    public ?string $bankFeedTransactionDirection = null;

    public string $transactionType = 'expense';

    public string $descriptionInput = '';

    public ?int $accountId = null;

    public ?int $categoryId = null;

    public string $date = '';

    public string $notes = '';

    public string $cleanDescription = '';

    public ?int $transferToAccountId = null;

    public string $mode = 'enter';

    public ?int $editingPlannedTransactionId = null;

    public string $frequency = 'every-month';

    public string $untilType = 'always';

    public ?string $untilDate = null;

    public ?string $occurrenceDate = null;

    /** Off by default; openForEdit() ticks it for an uncategorised row that is neither a transfer nor split. */
    public bool $categoriseMatching = false;

    public string $categoriseMatchValue = '';

    /**
     * Manual rows the pending "categorise matching" rule would contradict:
     * category full path => count. Display-only, recomputed when the toggle,
     * match value or category changes.
     *
     * @var array<string, int>
     */
    public array $categoriseContradictions = [];

    /**
     * The contradicting rows listed under the warning. Locked because their ids
     * are what a consented move may touch: the form must not be able to widen
     * that set.
     *
     * @var list<array{id: int, description: string, date: string, category: string}>
     */
    #[Locked]
    public array $categoriseContradictionRows = [];

    /** The chosen category and the sorted listed ids: the value the move checkbox submits. */
    #[Locked]
    public string $categoriseContradictionKey = '';

    /**
     * Keys of the lists the user ticked "move" for. A tick given for an earlier
     * list does not hold the current key, so it renders unticked and moves
     * nothing, even when it reached the server after the list changed.
     *
     * @var list<string>
     */
    public array $categoriseMoveConsent = [];

    #[Locked]
    public bool $originalWasTransfer = false;

    /** Explicit choice among several possible opposite legs when linking a bank-feed row. */
    public ?int $selectedCandidateId = null;

    /** Per request: this request's own updates changed a list the user had ticked. */
    private bool $categoriseConsentWithdrawn = false;

    #[On('open-transaction-modal')]
    public function openForAdd(string $date): void
    {
        $this->resetForm();
        $this->date = $date;
        $this->mode = CarbonImmutable::parse($date)->isFuture() ? 'plan' : 'enter';
        $this->showModal = true;
    }

    #[On('edit-transaction')]
    public function openForEdit(int $id): void
    {
        $transaction = Transaction::findCurrentVersion($id, auth()->id());

        if (! $transaction) {
            return;
        }

        if ($transaction->transfer_pair_id) {
            $pairRow = Transaction::query()
                ->where('user_id', auth()->id())
                ->find($transaction->transfer_pair_id);

            if ($pairRow) {
                $currentIsFeed = $transaction->source->isBankFeed();
                $pairIsFeed = $pairRow->source->isBankFeed();

                // Edit the debit side, but always edit the imported row of a feed/manual pair
                // so a manual mirror is never treated as the row to convert or delete.
                $swap = ($transaction->direction === TransactionDirection::Credit && ! ($currentIsFeed && ! $pairIsFeed))
                    || (! $currentIsFeed && $pairIsFeed);

                if ($swap) {
                    $transaction = $pairRow;
                }
            }
        }

        $this->resetForm();

        $this->editingTransactionId = $transaction->id;
        $this->isBankFeedTransaction = $transaction->source->isBankFeed();
        $this->bankFeedTransactionDirection = $this->isBankFeedTransaction ? $transaction->direction->value : null;
        $this->originalWasTransfer = $transaction->transfer_pair_id !== null;

        if ($transaction->transfer_pair_id) {
            $this->transactionType = 'transfer';

            $pair = Transaction::query()
                ->where('user_id', auth()->id())
                ->find($transaction->transfer_pair_id);

            if ($pair) {
                $debitSide = $transaction->direction === TransactionDirection::Debit ? $transaction : $pair;
                $creditSide = $transaction->direction === TransactionDirection::Credit ? $transaction : $pair;

                $this->accountId = $debitSide->account_id;
                $this->transferToAccountId = $creditSide->account_id;
            }
        } else {
            $this->transactionType = $transaction->direction === TransactionDirection::Debit
                ? 'expense'
                : 'income';

            $this->accountId = $transaction->account_id;
        }

        $dollars = number_format(abs($transaction->amount) / 100, 2, '.', '');
        $description = $transaction->description ?? '';
        $this->descriptionInput = $description !== '' ? "{$dollars} {$description}" : $dollars;

        if ($this->isBankFeedTransaction) {
            $this->cleanDescription = $transaction->clean_description ?? '';
        }

        $this->categoryId = $transaction->category_id;
        $this->date = $transaction->post_date->format('Y-m-d');
        $this->notes = $transaction->notes ?? '';
        $this->categoriseMatchValue = app(CategoryRuleGenerator::class)->suggestMatchValue($transaction);
        $this->categoriseMatching = $transaction->category_id === null
            && $transaction->transfer_pair_id === null
            && ! $transaction->isSplit();

        $this->showModal = true;
    }

    #[On('copy-transaction')]
    public function openForCopy(int $id): void
    {
        $transaction = Transaction::findCurrentVersion($id, auth()->id());

        if (! $transaction) {
            return;
        }

        $this->resetForm();

        // Pre-fill a brand-new entry from an existing transaction. editingTransactionId
        // stays null so save() creates a fresh transaction rather than editing the source.
        $this->transactionType = $transaction->direction === TransactionDirection::Debit
            ? 'expense'
            : 'income';
        $this->accountId = $transaction->account_id;

        $dollars = number_format(abs($transaction->amount) / 100, 2, '.', '');
        $description = $transaction->description ?? '';
        $this->descriptionInput = $description !== '' ? "{$dollars} {$description}" : $dollars;

        $this->categoryId = $transaction->category_id;
        $this->date = CarbonImmutable::now()->format('Y-m-d');

        $this->showModal = true;
    }

    #[On('edit-planned-transaction')]
    public function openForEditPlanned(int $id, ?string $occurrenceDate = null): void
    {
        $planned = PlannedTransaction::query()
            ->where('user_id', auth()->id())
            ->find($id);

        if (! $planned) {
            return;
        }

        $this->resetForm();

        $this->editingPlannedTransactionId = $planned->id;
        $this->occurrenceDate = $occurrenceDate;
        $this->mode = 'plan';
        $this->originalWasTransfer = $planned->transfer_to_account_id !== null;

        if ($planned->transfer_to_account_id !== null) {
            $this->transactionType = 'transfer';
            $this->transferToAccountId = $planned->transfer_to_account_id;
        } else {
            $this->transactionType = $planned->direction === TransactionDirection::Debit
                ? 'expense'
                : 'income';
        }

        $dollars = number_format(abs($planned->amount) / 100, 2, '.', '');
        $description = $planned->description ?? '';
        $this->descriptionInput = $description !== '' ? "{$dollars} {$description}" : $dollars;

        $this->accountId = $planned->account_id;
        $this->categoryId = $planned->category_id;
        $this->date = $occurrenceDate ?? $planned->start_date->format('Y-m-d');
        $this->frequency = $planned->frequency->value;

        if ($planned->until_date !== null) {
            $this->untilType = 'until-date';
            $this->untilDate = $planned->until_date->format('Y-m-d');
        }

        $this->showModal = true;
    }

    /**
     * @throws Throwable
     */
    public function save(): void
    {
        if ($this->categoriseConsentWithdrawn) {
            $this->addError('categoriseMoveConsent', __('The matching transactions changed. Check the list and tick again to move them.'));

            return;
        }

        $this->validate($this->formRules(), [
            'date.after' => __('Planned transactions start after today.'),
        ]);

        if (! $this->resolveSave()) {
            return;
        }

        $this->showModal = false;
        $this->resetForm();
        $this->dispatch('transaction-saved');
    }

    /**
     * Switching an existing transaction to plan mode prefills a new plan from
     * the row: a bank-feed row's description comes from its clean description
     * (falling back to the bank's), and the start date defaults to the first
     * occurrence after today. Switching back to enter restores the fields the
     * prefill overwrote, so saving the entered row never moves its post date.
     */
    public function updatedMode(): void
    {
        if ($this->editingTransactionId === null) {
            return;
        }

        $transaction = Transaction::query()
            ->where('user_id', auth()->id())
            ->find($this->editingTransactionId);

        if (! $transaction) {
            return;
        }

        $dollars = number_format(abs($transaction->amount) / 100, 2, '.', '');

        if ($this->mode !== 'plan') {
            $description = $transaction->description ?? '';
            if ($this->isBankFeedTransaction) {
                $this->descriptionInput = $description !== '' ? "{$dollars} {$description}" : $dollars;
            }
            $this->date = $transaction->post_date->format('Y-m-d');
            $this->refreshCategoriseContradictions();

            return;
        }

        if ($this->isBankFeedTransaction) {
            $description = $this->cleanDescription !== '' ? $this->cleanDescription : ($transaction->description ?? '');
            $this->descriptionInput = $description !== '' ? "{$dollars} {$description}" : $dollars;
        }

        $this->date = $this->nextPlanStartDate($transaction->post_date->toImmutable());
        $this->refreshCategoriseContradictions();
    }

    public function updatedFrequency(): void
    {
        $transaction = $this->editingTransactionForPlan();

        if (! $transaction) {
            return;
        }

        $this->date = $this->nextPlanStartDate($transaction->post_date->toImmutable());
    }

    public function updatedTransactionType(): void
    {
        if ($this->transactionType !== 'transfer' && ! $this->isBankFeedTransaction) {
            $this->notes = '';
        }

        $this->refreshCategoriseContradictions();
    }

    /** Swaps From/To on a manual transfer; a hidden (untracked) account can never become the From side. */
    public function swapTransferAccounts(): void
    {
        if ($this->isBankFeedTransaction || $this->transferToAccountId === null) {
            return;
        }

        $toIsTracked = Account::query()
            ->where('user_id', auth()->id())
            ->tracked()
            ->whereKey($this->transferToAccountId)
            ->exists();

        if (! $toIsTracked) {
            return;
        }

        [$this->accountId, $this->transferToAccountId] = [$this->transferToAccountId, $this->accountId];
    }

    /**
     * @throws Throwable
     */
    public function deleteTransaction(): void
    {
        $transaction = Transaction::query()
            ->where('user_id', auth()->id())
            ->find($this->editingTransactionId);

        if (! $transaction) {
            return;
        }

        if ($transaction->source->isBankFeed() && $transaction->parent_transaction_id === null) {
            return;
        }

        DB::transaction(static function () use ($transaction): void {
            if ($transaction->transfer_pair_id) {
                $pair = Transaction::query()
                    ->where('id', $transaction->transfer_pair_id)
                    ->where('user_id', auth()->id())
                    ->first();

                // Imported rows are never deleted; only a manual/mirror partner goes with this row.
                if ($pair instanceof Transaction && $pair->source->isBankFeed()) {
                    app(TransferLinker::class)->unlink($transaction);
                } else {
                    $pair?->delete();
                }
            }

            $transaction->delete();
        });

        $this->showModal = false;
        $this->resetForm();
        $this->dispatch('transaction-saved');
    }

    /**
     * Non-destructive unlink of the bank-feed row being edited (both legs stay).
     *
     * @throws Throwable
     */
    public function unlinkTransfer(): void
    {
        $row = $this->linkedBankFeedRow();

        if (! $row instanceof Transaction) {
            return;
        }

        app(TransferLinker::class)->unlink($row);

        $this->showModal = false;
        $this->resetForm();
        $this->dispatch('transaction-saved');
    }

    /**
     * @throws Throwable
     */
    public function confirmSuggestedTransfer(): void
    {
        $row = $this->suggestedBankFeedRow();

        if (! $row instanceof Transaction) {
            return;
        }

        if (! app(TransferLinker::class)->confirm($row)) {
            $this->addError('transactionType', __('That pair changed while the window was open. Nothing was confirmed.'));

            return;
        }

        $this->showModal = false;
        $this->resetForm();
        $this->dispatch('transaction-saved');
    }

    /**
     * @throws Throwable
     */
    public function rejectSuggestedTransfer(): void
    {
        $row = $this->suggestedBankFeedRow();

        if (! $row instanceof Transaction) {
            return;
        }

        app(TransferLinker::class)->markNotTransfer($row);

        $this->showModal = false;
        $this->resetForm();
        $this->dispatch('transaction-saved');
    }

    /** A pending "possible transfer" suggestion; the row is not a transfer until confirmed. */
    public function isSuggestedTransfer(): bool
    {
        return $this->suggestedBankFeedRow() instanceof Transaction;
    }

    /**
     * Whether any real opposite row exists for the bank-feed row being edited (any account).
     * Takes no client input: the row is always the user-scoped one being edited.
     */
    public function hasOppositeRows(): bool
    {
        $row = $this->editingTransactionId
            ? Transaction::query()->where('user_id', auth()->id())->find($this->editingTransactionId)
            : null;

        return $row instanceof Transaction && $this->isBankFeedTransaction && $this->hasRealOppositeRows($row);
    }

    /**
     * Possible opposite legs for the bank-feed row being turned into a transfer,
     * narrowed to the chosen counterpart account when one is picked.
     *
     * @return Collection<int, Transaction>
     */
    public function transferCandidates(): Collection
    {
        if (! $this->isBankFeedTransaction || $this->originalWasTransfer || $this->transactionType !== 'transfer' || ! $this->editingTransactionId) {
            return new Collection;
        }

        $row = Transaction::query()->where('user_id', auth()->id())->find($this->editingTransactionId);

        if (! $row instanceof Transaction) {
            return new Collection;
        }

        return app(TransferLinker::class)
            ->candidatesFor($row, includeRejected: true)
            ->when($this->transferToAccountId, fn (Collection $c): Collection => $c->where('account_id', $this->transferToAccountId))
            ->load('account')
            ->values();
    }

    public function render(): View
    {
        $accounts = auth()
            ->user()
            ->accounts()
            ->active()
            ->visible()
            ->orderBy('name')
            ->get();

        $categories = Category::visibleSortedByFullPath();

        return view('livewire.transaction-modal', [
            'accounts' => $accounts,
            // Normal entry only offers tracked accounts; a linked bank-feed row keeps showing
            // its read-only account even when that leg is an untracked mirror.
            'fromAccounts' => $accounts->filter(
                fn (Account $account): bool => $account->is_tracked
                    || ($this->isBankFeedTransaction && $this->originalWasTransfer && $account->id === $this->accountId),
            ),
            'categories' => $categories,
            'formatMoney' => MoneyCast::format(...),
            'parsedAmount' => $this->descriptionInput !== ''
                ? AmountParser::parse($this->descriptionInput)->amount
                : 0,
        ]);
    }

    public function deletePlannedTransaction(): void
    {
        $planned = PlannedTransaction::query()
            ->where('user_id', auth()->id())
            ->find($this->editingPlannedTransactionId);

        if (! $planned) {
            return;
        }

        $planned->delete();

        $this->showModal = false;
        $this->resetForm();
        $this->dispatch('transaction-saved');
    }

    /**
     * A planned occurrence opened from the calendar (carrying its occurrence
     * date) whose date is today or earlier — the user is entering/reconciling a
     * forecast that has now passed, rather than editing the plan definition.
     */
    public function isRealizableOccurrence(): bool
    {
        if ($this->editingPlannedTransactionId === null
            || $this->mode !== 'plan'
            || $this->occurrenceDate === null) {
            return false;
        }

        try {
            $occurrence = CarbonImmutable::createFromFormat('!Y-m-d', $this->occurrenceDate);
        } catch (Throwable) {
            return false;
        }

        return $occurrence instanceof CarbonImmutable
            && $occurrence->lessThanOrEqualTo(CarbonImmutable::today());
    }

    public function updatedCategoriseMatching(): void
    {
        $this->refreshCategoriseContradictions();
    }

    public function updatedCategoriseMatchValue(): void
    {
        $this->refreshCategoriseContradictions();
    }

    public function updatedCategoryId(): void
    {
        $this->refreshCategoriseContradictions();
    }

    public function updatedDescriptionInput(): void
    {
        $this->refreshCategoriseContradictions();
    }

    public function updatedCleanDescription(): void
    {
        $this->refreshCategoriseContradictions();
    }

    private function hasRealOppositeRows(Transaction $row): bool
    {
        return app(TransferLinker::class)->candidatesFor($row, includeRejected: true)->isNotEmpty();
    }

    /** The row being edited is an imported (bank-feed) row, judged by its stored source, not the client-held flag. */
    private function persistedRowIsBankFeed(): bool
    {
        $row = Transaction::query()->where('user_id', auth()->id())->find($this->editingTransactionId);

        return $row instanceof Transaction && in_array($row->source, TransactionSource::bankFeed(), true);
    }

    /**
     * @throws Throwable
     */
    private function saveBankFeedTransaction(): bool
    {
        $row = Transaction::query()->where('user_id', auth()->id())->find($this->editingTransactionId);

        if (! $row instanceof Transaction) {
            return false;
        }

        $wantsTransfer = $this->transactionType === 'transfer';
        $isLinked = $row->transfer_pair_id !== null;

        if ($isLinked && $this->originalWasTransfer && ! $wantsTransfer) {
            // Unlink first so the edit's new version does not inherit the stale pair id.
            app(TransferLinker::class)->unlink($row);

            return ! $this->bankFeedFieldsChanged($row) || $this->updateTransaction();
        }

        if (! $isLinked && $wantsTransfer) {
            return $this->linkBankFeedRow($row);
        }

        return $this->updateTransaction();
    }

    private function bankFeedFieldsChanged(Transaction $row): bool
    {
        return $row->category_id !== $this->categoryId
            || ($row->notes ?? '') !== $this->notes
            || ($row->clean_description ?? '') !== $this->cleanDescription;
    }

    /**
     * Links the bank-feed row to a real opposite row (one candidate: automatically;
     * several: the user's explicit pick) or, when the user chose an untracked account
     * as counterpart, to a mirror row on it. Never creates a duplicate imported leg.
     *
     * @throws Throwable
     */
    private function linkBankFeedRow(Transaction $row): bool
    {
        $linker = app(TransferLinker::class);

        $counterpart = $this->transferToAccountId
            ? Account::query()->where('user_id', auth()->id())->find($this->transferToAccountId)
            : null;

        $untracked = $counterpart instanceof Account && ! $counterpart->is_tracked ? $counterpart : null;
        $chosen = null;

        // A real opposite row always wins: a hidden account is only for rows with no match,
        // otherwise a mirror would sit next to the real leg (the duplicate this flow prevents).
        if ($untracked instanceof Account && $this->hasRealOppositeRows($row)) {
            $this->addError('transferToAccountId', __('A matching transaction exists. Link to it instead of a hidden account.'));

            return false;
        }

        if (! $untracked instanceof Account) {
            $candidates = $this->transferCandidates();

            if ($candidates->isEmpty()) {
                $this->addError('transferToAccountId', __('No matching transaction found to link. Pick a hidden (untracked) account as the counterpart instead.'));

                return false;
            }

            $chosen = $candidates->count() === 1
                ? $candidates->first()
                : $candidates->firstWhere('id', $this->selectedCandidateId);

            if (! $chosen instanceof Transaction) {
                $this->addError('selectedCandidateId', __('Several transactions match. Choose which one to link.'));

                return false;
            }
        }

        // A refused link must roll back the edit it followed: throw inside the callback so the
        // transaction rolls back, and translate the refusal into a field error outside it.
        try {
            return DB::transaction(function () use ($linker, $untracked, $chosen, $row): bool {
                $current = $row;

                // Only version the imported row when the user actually edited something.
                if ($this->bankFeedFieldsChanged($row)) {
                    if (! $this->updateTransaction()) {
                        return false;
                    }

                    $current = Transaction::findCurrentVersion($row->id, (int) auth()->id());
                }

                if ($untracked instanceof Account) {
                    $linker->linkToUntrackedAccount($current, $untracked, TransferLinkSource::Manual);
                } elseif (! $linker->link($current, $chosen, TransferLinkSource::Manual)) {
                    throw new TransferAlreadyLinkedException(__('That row was just linked elsewhere, please pick again.'));
                }

                return true;
            });
        } catch (TransferLinkRefusedException $e) {
            $this->addError($untracked instanceof Account ? 'transferToAccountId' : 'selectedCandidateId', $e->getMessage());

            return false;
        }
    }

    private function suggestedBankFeedRow(): ?Transaction
    {
        if (! $this->isBankFeedTransaction || ! $this->editingTransactionId) {
            return null;
        }

        $row = Transaction::query()->where('user_id', auth()->id())->find($this->editingTransactionId);

        return $row instanceof Transaction && $row->suggested_pair_id !== null && $row->transfer_pair_id === null ? $row : null;
    }

    private function linkedBankFeedRow(): ?Transaction
    {
        if (! $this->isBankFeedTransaction || ! $this->originalWasTransfer || ! $this->editingTransactionId) {
            return null;
        }

        $row = Transaction::query()->where('user_id', auth()->id())->find($this->editingTransactionId);

        return $row instanceof Transaction && $row->transfer_pair_id !== null ? $row : null;
    }

    /**
     * @throws Throwable
     */
    private function resolveSave(): bool
    {
        if ($this->editingTransactionId && $this->mode === 'plan') {
            return $this->isBankFeedTransaction
                ? $this->planFromBankFeedRow()
                : $this->convertEnteredToPlanned();
        }

        if ($this->editingPlannedTransactionId && $this->mode === 'enter') {
            return $this->convertPlannedToEntered();
        }

        if ($this->isRealizableOccurrence()) {
            return $this->realizePlannedOccurrence();
        }

        if ($this->editingPlannedTransactionId) {
            return $this->updatePlannedTransaction();
        }

        if ($this->editingTransactionId) {
            if ($this->isBankFeedTransaction || $this->persistedRowIsBankFeed()) {
                return $this->saveBankFeedTransaction();
            }

            $nowIsTransfer = $this->transactionType === 'transfer';

            return match (true) {
                ! $this->originalWasTransfer && ! $nowIsTransfer => $this->updateTransaction(),
                $this->originalWasTransfer && $nowIsTransfer => $this->updateTransfer(),
                ! $this->originalWasTransfer && $nowIsTransfer => $this->convertToTransfer(),
                default => $this->convertFromTransfer(),
            };
        }

        if ($this->mode === 'plan') {
            return $this->createPlannedTransaction();
        }

        return $this->transactionType === 'transfer'
            ? $this->createTransfer()
            : $this->createTransaction();
    }

    private function createTransaction(): bool
    {
        $parsed = AmountParser::parse($this->descriptionInput);

        if ($parsed->amount <= 0) {
            $this->addError('descriptionInput', __('The amount must be greater than zero.'));

            return false;
        }

        app(TransactionIngestor::class)->ingest(
            new Transaction($this->manualSingleAttributes($parsed, null)),
        );

        return true;
    }

    /**
     * @throws Throwable
     */
    private function createTransfer(): bool
    {
        $parsed = AmountParser::parse($this->descriptionInput);

        if ($parsed->amount <= 0) {
            $this->addError('descriptionInput', __('The amount must be greater than zero.'));

            return false;
        }

        DB::transaction(fn () => $this->createTransferPair($parsed));

        return true;
    }

    private function createPlannedTransaction(): bool
    {
        $parsed = AmountParser::parse($this->descriptionInput);

        if ($parsed->amount <= 0) {
            $this->addError('descriptionInput', __('The amount must be greater than zero.'));

            return false;
        }

        PlannedTransaction::query()->create($this->buildPlannedTransactionData($parsed));

        return true;
    }

    private function updatePlannedTransaction(): bool
    {
        $resolved = $this->resolvePlannedTransactionWithParsedAmount();

        if ($resolved === false) {
            return false;
        }

        [$planned, $parsed] = $resolved;

        $data = $this->buildPlannedTransactionData($parsed);
        unset($data['user_id'], $data['is_active']);

        $planned->update($data);

        return true;
    }

    private function updateTransaction(): bool
    {
        $resolved = $this->resolveTransactionWithParsedAmount(allowZero: true);

        if ($resolved === false) {
            return false;
        }

        [$transaction, $parsed] = $resolved;

        if ($transaction->source->isBankFeed()) {
            $child = $transaction->createChild([
                'category_id' => $this->categoryId,
                'notes' => $this->notes !== '' ? $this->notes : null,
                ...$this->editedDescriptors($transaction),
            ]);

            // The partner follows the new version, but only for links that still hold under lock.
            app(TransferLinker::class)->followVersion($transaction, $child);
        } else {
            $child = $transaction->createChild([
                'account_id' => $this->accountId,
                'category_id' => $this->categoryId,
                'amount' => $parsed->amount,
                'direction' => $this->transactionType === 'expense'
                    ? TransactionDirection::Debit
                    : TransactionDirection::Credit,
                ...$this->editedDescriptors($transaction),
                'post_date' => $this->date,
                'notes' => $this->notes !== '' ? $this->notes : null,
            ]);
        }

        $this->applyCategoriseMatching($child);

        return true;
    }

    /**
     * The descriptor fields updateTransaction() gives the new version. A rule
     * with a blank match value falls back to them, so the contradiction
     * preview applies them too.
     *
     * @return array<string, string|null>
     */
    private function editedDescriptors(Transaction $transaction): array
    {
        return $transaction->source->isBankFeed()
            ? ['clean_description' => $this->cleanDescription !== '' ? $this->cleanDescription : null]
            : ['description' => AmountParser::parse($this->descriptionInput)->description];
    }

    /**
     * @throws Throwable
     */
    private function updateTransfer(): bool
    {
        $resolved = $this->resolveTransferPairWithParsedAmount();

        if ($resolved === false) {
            return false;
        }

        [$debitSide, $creditSide, $parsed] = $resolved;

        DB::transaction(function () use ($debitSide, $creditSide, $parsed): void {
            $shared = [
                'category_id' => $this->categoryId,
                'amount' => $parsed->amount,
                'description' => $parsed->description,
                'post_date' => $this->date,
                'notes' => $this->notes !== '' ? $this->notes : null,
            ];

            $debitChild = $debitSide->createChild($shared + ['account_id' => $this->accountId]);
            $creditChild = $creditSide->createChild($shared + ['account_id' => $this->transferToAccountId]);

            $manual = ['transfer_link_source' => TransferLinkSource::Manual];

            $debitChild->update(['transfer_pair_id' => $creditChild->id] + $manual);
            $creditChild->update(['transfer_pair_id' => $debitChild->id] + $manual);
        });

        return true;
    }

    /**
     * @throws Throwable
     */
    private function convertToTransfer(): bool
    {
        $resolved = $this->resolveTransactionWithParsedAmount();

        if ($resolved === false) {
            return false;
        }

        [$transaction, $parsed] = $resolved;

        DB::transaction(function () use ($transaction, $parsed): void {
            $shared = [
                'amount' => $parsed->amount,
                'description' => $parsed->description,
                'post_date' => $this->date,
                'category_id' => $this->categoryId,
                'notes' => $this->notes !== '' ? $this->notes : null,
            ];

            $debitChild = $transaction->createChild($shared + [
                'account_id' => $this->accountId,
                'direction' => TransactionDirection::Debit,
            ]);

            $credit = Transaction::query()->create($shared + [
                'user_id' => auth()->id(),
                'account_id' => $this->transferToAccountId,
                'direction' => TransactionDirection::Credit,
                'source' => TransactionSource::Manual,
                'status' => TransactionStatus::Posted,
            ]);

            $manual = ['transfer_link_source' => TransferLinkSource::Manual];

            $debitChild->update(['transfer_pair_id' => $credit->id] + $manual);
            $credit->update(['transfer_pair_id' => $debitChild->id] + $manual);
        });

        return true;
    }

    /**
     * @throws Throwable
     */
    private function convertFromTransfer(): bool
    {
        $resolved = $this->resolveTransferPairWithParsedAmount();

        if ($resolved === false) {
            return false;
        }

        [$debitSide, $creditSide, $parsed] = $resolved;

        $direction = $this->transactionType === 'expense'
            ? TransactionDirection::Debit
            : TransactionDirection::Credit;

        DB::transaction(function () use ($debitSide, $creditSide, $parsed, $direction): void {
            // An imported row is never deleted: unlink it (stamping both legs) and keep it.
            $keepCreditSide = $creditSide->source->isBankFeed();

            if ($keepCreditSide) {
                app(TransferLinker::class)->unlink($debitSide);
            }

            $debitSide->createChild([
                'account_id' => $this->accountId,
                'direction' => $direction,
                'amount' => $parsed->amount,
                'description' => $parsed->description,
                'post_date' => $this->date,
                'category_id' => $this->categoryId,
                'notes' => $this->notes !== '' ? $this->notes : null,
                'transfer_pair_id' => null,
                'transfer_link_source' => $keepCreditSide ? TransferLinkSource::Unlinked : null,
            ]);

            if (! $keepCreditSide) {
                $creditSide->delete();
            }
        });

        return true;
    }

    /**
     * @throws Throwable
     */
    private function convertEnteredToPlanned(): bool
    {
        $resolved = $this->resolveTransactionWithParsedAmount();

        if ($resolved === false) {
            return false;
        }

        [$transaction, $parsed] = $resolved;

        // Keep the source transaction. When it is a plain (non-transfer) posting,
        // reconcile it to the new plan so its occurrence on the start date is not
        // double-counted on the calendar; its own fields are left untouched.
        $isPlainSource = $this->transactionType !== 'transfer' && $transaction->transfer_pair_id === null;

        DB::transaction(function () use ($transaction, $parsed, $isPlainSource): void {
            $planned = PlannedTransaction::query()->create($this->buildPlannedTransactionData($parsed));

            if ($isPlainSource) {
                $transaction->update(['planned_transaction_id' => $planned->id]);
            }
        });

        $this->applyCategoriseMatching($transaction);

        return true;
    }

    /**
     * @throws Throwable
     */
    private function applyCategoriseMatching(Transaction $source): void
    {
        if (! $this->categoriseMatching
            || $this->categoryId === null
            || $this->transactionType === 'transfer'
            || $source->transfer_pair_id !== null) {
            return;
        }

        $generator = app(CategoryRuleGenerator::class);
        $generator->generateAndApply(
            $source,
            $this->categoryId,
            $this->categoriseMatchValue,
            $source->source->isBankFeed() ? $this->cleanDescription : null,
        );

        if ($this->categoriseContradictionKey !== ''
            && in_array($this->categoriseContradictionKey, $this->categoriseMoveConsent, true)) {
            $generator->moveManualContradictions(
                $source,
                $this->categoryId,
                $this->categoriseMatchValue,
                array_column($this->categoriseContradictionRows, 'id'),
            );
        }
    }

    /**
     * Warn before the rule is created when its match value also catches rows
     * the user filed elsewhere themselves, and list them. Built on
     * ManualContradictionChecker, which also classifies rows for the
     * transaction list's rule preview, so the two warnings cannot disagree.
     */
    private function refreshCategoriseContradictions(): void
    {
        $source = $this->categoriseContradictionSource();

        // Saving in enter mode re-files the edited row itself, so it cannot
        // contradict its own rule; plan mode leaves it filed where it is.
        $contradictions = $source === null
            ? collect()
            : app(CategoryRuleGenerator::class)
                ->manualContradictions($source, $this->categoryId, $this->categoriseMatchValue)
                ->reject(fn (Transaction $transaction): bool => $this->mode === 'enter' && $transaction->id === $source->id)
                ->sortByDesc('post_date');

        $this->categoriseContradictions = $contradictions
            ->countBy(fn (Transaction $transaction): string => $transaction->category->fullPath())
            ->sortDesc()
            ->all();

        $this->categoriseContradictionRows = $contradictions
            ->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'description' => $transaction->description,
                'date' => $transaction->post_date->format('D j M Y'),
                'category' => $transaction->category->fullPath(),
            ])
            ->values()
            ->all();

        $ids = array_column($this->categoriseContradictionRows, 'id');
        sort($ids);
        $key = $ids === [] ? '' : $this->categoryId.':'.implode(',', $ids);

        if ($key !== $this->categoriseContradictionKey) {
            $this->categoriseConsentWithdrawn = $this->categoriseConsentWithdrawn
                || ($key !== '' && in_array($this->categoriseContradictionKey, $this->categoriseMoveConsent, true));
            $this->categoriseMoveConsent = [];
            $this->categoriseContradictionKey = $key;
        }
    }

    /**
     * The row the pending rule will be built from, as save will see it: in
     * enter mode that is the new version carrying the edited descriptors; in
     * plan mode the persisted row itself. Never saved.
     */
    private function categoriseContradictionSource(): ?Transaction
    {
        if (! $this->categoriseMatching
            || $this->categoryId === null
            || $this->transactionType === 'transfer'
            || $this->editingTransactionId === null) {
            return null;
        }

        $source = Transaction::query()
            ->where('user_id', auth()->id())
            ->find($this->editingTransactionId);

        if ($source === null || $source->transfer_pair_id !== null) {
            return null;
        }

        if ($this->mode === 'enter') {
            $source->fill($this->editedDescriptors($source));
        }

        return $source;
    }

    /**
     * @throws Throwable
     */
    private function convertPlannedToEntered(): bool
    {
        $resolved = $this->resolvePlannedTransactionWithParsedAmount();

        if ($resolved === false) {
            return false;
        }

        [$planned, $parsed] = $resolved;

        DB::transaction(function () use ($planned, $parsed): void {
            if ($this->transactionType === 'transfer') {
                $this->createTransferPair($parsed);
            } else {
                $this->createSingleTransaction($parsed);
            }

            $planned->delete();
        });

        return true;
    }

    /**
     * Realize a past planned occurrence: record the posted transaction it
     * forecast and link it to the plan, leaving the recurring plan active so
     * future occurrences keep forecasting. Triggered when a planned occurrence
     * whose date is today-or-earlier is opened from the calendar.
     *
     * @throws Throwable
     */
    private function realizePlannedOccurrence(): bool
    {
        $resolved = $this->resolvePlannedTransactionWithParsedAmount();

        if ($resolved === false) {
            return false;
        }

        [$planned, $parsed] = $resolved;

        if ($this->transactionType === 'transfer') {
            DB::transaction(fn () => $this->createTransferPair($parsed, $planned->id));

            return true;
        }

        $this->createSingleTransaction($parsed, $planned->id);

        return true;
    }

    /** @return array{Transaction, AmountParseResult}|false */
    private function resolveTransactionWithParsedAmount(bool $allowZero = false): array|false
    {
        $transaction = Transaction::query()
            ->where('user_id', auth()->id())
            ->find($this->editingTransactionId);

        if (! $transaction) {
            return false;
        }

        $parsed = AmountParser::parse($this->descriptionInput);

        if ($allowZero ? ($parsed->amount < 0 || ! $parsed->hasAmount) : $parsed->amount <= 0) {
            $this->addError('descriptionInput', $allowZero ? __('Enter a valid amount.') : __('The amount must be greater than zero.'));

            return false;
        }

        return [$transaction, $parsed];
    }

    /** @return array{PlannedTransaction, AmountParseResult}|false */
    private function resolvePlannedTransactionWithParsedAmount(): array|false
    {
        $planned = PlannedTransaction::query()
            ->where('user_id', auth()->id())
            ->find($this->editingPlannedTransactionId);

        if (! $planned) {
            return false;
        }

        $parsed = AmountParser::parse($this->descriptionInput);

        if ($parsed->amount <= 0) {
            $this->addError('descriptionInput', __('The amount must be greater than zero.'));

            return false;
        }

        return [$planned, $parsed];
    }

    /** @return array{Transaction, Transaction, AmountParseResult}|false */
    private function resolveTransferPairWithParsedAmount(): array|false
    {
        $transaction = Transaction::query()
            ->where('user_id', auth()->id())
            ->find($this->editingTransactionId);

        if (! $transaction || ! $transaction->transfer_pair_id) {
            return false;
        }

        $pair = Transaction::query()
            ->where('user_id', auth()->id())
            ->find($transaction->transfer_pair_id);

        if (! $pair) {
            return false;
        }

        $parsed = AmountParser::parse($this->descriptionInput);

        if ($parsed->amount <= 0) {
            $this->addError('descriptionInput', __('The amount must be greater than zero.'));

            return false;
        }

        $debitSide = $transaction->direction === TransactionDirection::Debit ? $transaction : $pair;
        $creditSide = $transaction->direction === TransactionDirection::Credit ? $transaction : $pair;

        return [$debitSide, $creditSide, $parsed];
    }

    private function createSingleTransaction(AmountParseResult $parsed, ?int $plannedTransactionId = null): void
    {
        Transaction::query()->create($this->manualSingleAttributes($parsed, $plannedTransactionId));
    }

    /**
     * @return array<string, mixed>
     */
    private function manualSingleAttributes(AmountParseResult $parsed, ?int $plannedTransactionId): array
    {
        return [
            'user_id' => auth()->id(),
            'account_id' => $this->accountId,
            'category_id' => $this->categoryId,
            'amount' => $parsed->amount,
            'direction' => $this->transactionType === 'expense'
                ? TransactionDirection::Debit
                : TransactionDirection::Credit,
            'description' => $parsed->description,
            'post_date' => $this->date,
            'status' => TransactionStatus::Posted,
            'source' => TransactionSource::Manual,
            'notes' => $this->notes !== '' ? $this->notes : null,
            'planned_transaction_id' => $plannedTransactionId,
        ];
    }

    private function createTransferPair(AmountParseResult $parsed, ?int $plannedTransactionId = null): void
    {
        $shared = [
            'planned_transaction_id' => $plannedTransactionId,
            'user_id' => auth()->id(),
            'category_id' => $this->categoryId,
            'amount' => $parsed->amount,
            'description' => $parsed->description,
            'post_date' => $this->date,
            'status' => TransactionStatus::Posted,
            'source' => TransactionSource::Manual,
            'notes' => $this->notes !== '' ? $this->notes : null,
        ];

        $debit = Transaction::query()->create($shared + [
            'account_id' => $this->accountId,
            'direction' => TransactionDirection::Debit,
        ]);

        $credit = Transaction::query()->create($shared + [
            'account_id' => $this->transferToAccountId,
            'direction' => TransactionDirection::Credit,
        ]);

        $manual = ['transfer_link_source' => TransferLinkSource::Manual];

        $debit->update(['transfer_pair_id' => $credit->id] + $manual);
        $credit->update(['transfer_pair_id' => $debit->id] + $manual);
    }

    /** @return array<string, mixed> */
    private function buildPlannedTransactionData(AmountParseResult $parsed): array
    {
        $direction = match ($this->transactionType) {
            'income' => TransactionDirection::Credit,
            default => TransactionDirection::Debit,
        };

        return [
            'user_id' => auth()->id(),
            'account_id' => $this->accountId,
            'transfer_to_account_id' => $this->transactionType === 'transfer'
                ? $this->transferToAccountId
                : null,
            'category_id' => $this->categoryId,
            'amount' => $parsed->amount,
            'direction' => $direction,
            'description' => $parsed->description,
            'start_date' => $this->date,
            'frequency' => RecurrenceFrequency::from($this->frequency),
            'until_date' => $this->untilType === 'until-date' ? $this->untilDate : null,
            'is_active' => true,
        ];
    }

    /**
     * Plan a new recurring transaction from a bank-feed row. The bank-feed row
     * is never modified: no child version and no plan link is recorded on it.
     */
    private function planFromBankFeedRow(): bool
    {
        $resolved = $this->resolveTransactionWithParsedAmount();

        if ($resolved === false) {
            return false;
        }

        [$transaction, $parsed] = $resolved;

        PlannedTransaction::query()->create([
            ...$this->buildPlannedTransactionData($parsed),
            'transfer_to_account_id' => null,
            'amount' => abs($transaction->amount),
            'direction' => $transaction->direction,
            'account_id' => $transaction->account_id,
            'description' => filled($transaction->clean_description) ? $transaction->clean_description : $transaction->description,
        ]);

        $this->applyCategoriseMatching($transaction);

        return true;
    }

    /** The transaction being edited when it is being switched into a new plan. */
    private function editingTransactionForPlan(): ?Transaction
    {
        if ($this->editingTransactionId === null || $this->mode !== 'plan') {
            return null;
        }

        return Transaction::query()
            ->where('user_id', auth()->id())
            ->find($this->editingTransactionId);
    }

    /**
     * First occurrence of the selected frequency strictly after today, starting
     * from the row's post date. Non-repeating plans (or a frequency that fails
     * to advance) default to tomorrow.
     */
    private function nextPlanStartDate(CarbonImmutable $from): string
    {
        $today = CarbonImmutable::today();
        $frequency = RecurrenceFrequency::tryFrom($this->frequency);
        $date = $from->startOfDay();

        while ($date->lessThanOrEqualTo($today)) {
            $next = $frequency?->nextOccurrence($date);

            if ($next === null || $next->lessThanOrEqualTo($date)) {
                return $today->addDay()->format('Y-m-d');
            }

            $date = $next;
        }

        return $date->format('Y-m-d');
    }

    private function isTransfer(): bool
    {
        return $this->transactionType === 'transfer';
    }

    private function resetForm(): void
    {
        $this->editingTransactionId = null;
        $this->editingPlannedTransactionId = null;
        $this->occurrenceDate = null;
        $this->categoriseMatching = false;
        $this->categoriseMatchValue = '';
        $this->categoriseContradictions = [];
        $this->categoriseContradictionRows = [];
        $this->categoriseContradictionKey = '';
        $this->categoriseMoveConsent = [];
        $this->isBankFeedTransaction = false;
        $this->bankFeedTransactionDirection = null;
        $this->transactionType = 'expense';
        $this->descriptionInput = '';
        $this->accountId = null;
        $this->categoryId = null;
        $this->date = '';
        $this->notes = '';
        $this->cleanDescription = '';
        $this->transferToAccountId = null;
        $this->originalWasTransfer = false;
        $this->selectedCandidateId = null;
        $this->mode = 'enter';
        $this->frequency = 'every-month';
        $this->untilType = 'always';
        $this->untilDate = null;
        $this->resetValidation();
    }

    /**
     * A bank-feed row's direction is the bank's: a debit can only be an expense or a
     * transfer, a credit only income or a transfer. Manual rows may take any type.
     *
     * @return list<string>
     */
    private function allowedTransactionTypes(): array
    {
        return match ($this->isBankFeedTransaction ? $this->bankFeedTransactionDirection : null) {
            TransactionDirection::Debit->value => ['expense', 'transfer'],
            TransactionDirection::Credit->value => ['income', 'transfer'],
            default => ['expense', 'income', 'transfer'],
        };
    }

    /** @return array<string, mixed> */
    private function formRules(): array
    {
        $rules = [
            'mode' => ['required', Rule::in(['enter', 'plan'])],
            'transactionType' => ['required', Rule::in($this->allowedTransactionTypes())],
            'descriptionInput' => ['required', 'string', 'max:255'],
            'accountId' => [
                'required',
                Rule::exists('accounts', 'id')
                    ->where('user_id', auth()->id())
                    // Untracked accounts are for transfer counterparts only. A linked
                    // bank-feed row may already sit on one as its (read-only) other leg.
                    ->when(
                        ! ($this->isBankFeedTransaction && $this->originalWasTransfer),
                        static fn ($rule) => $rule->where('is_tracked', true),
                    ),
            ],
            'categoryId' => [
                'nullable',
                Rule::exists('categories', 'id')->where('is_hidden', 0),
            ],
            'date' => $this->mode === 'plan' && $this->editingPlannedTransactionId === null
                ? ['required', 'date_format:Y-m-d', 'after:today']
                : ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'cleanDescription' => ['nullable', 'string', 'max:255'],
            'transferToAccountId' => $this->isTransfer()
                ? [
                    $this->isBankFeedTransaction && ! $this->originalWasTransfer ? 'nullable' : 'required',
                    // Any active account of the user, tracked or untracked (hidden).
                    Rule::exists('accounts', 'id')
                        ->where('user_id', auth()->id())
                        ->whereIn('status', [AccountStatus::Active->value, AccountStatus::Available->value]),
                    Rule::notIn([$this->accountId]),
                ]
                : ['nullable'],
        ];

        if ($this->mode === 'plan') {
            $rules['frequency'] = ['required', Rule::in(array_column(RecurrenceFrequency::cases(), 'value'))];
            $rules['untilType'] = ['required', Rule::in(['always', 'until-date'])];
            $rules['untilDate'] = $this->untilType === 'until-date'
                ? ['required', 'date_format:Y-m-d', 'after_or_equal:date']
                : ['nullable'];
        }

        return $rules;
    }
}
