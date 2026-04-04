<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Support\AmountParser;
use App\Support\AmountParseResult;
use Carbon\CarbonImmutable;
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
    public bool $isBasiqTransaction = false;

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

    #[Locked]
    public bool $originalWasTransfer = false;

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

        if ($transaction->transfer_pair_id && $transaction->direction === TransactionDirection::Credit) {
            $debitSide = Transaction::query()
                ->where('user_id', auth()->id())
                ->find($transaction->transfer_pair_id);

            if ($debitSide) {
                $transaction = $debitSide;
            }
        }

        $this->resetForm();

        $this->editingTransactionId = $transaction->id;
        $this->isBasiqTransaction = $transaction->source === TransactionSource::Basiq;
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

        if ($this->isBasiqTransaction) {
            $this->cleanDescription = $transaction->clean_description ?? '';
        }

        $this->categoryId = $transaction->category_id;
        $this->date = $transaction->post_date->format('Y-m-d');
        $this->notes = $transaction->notes ?? '';

        $this->showModal = true;
    }

    #[On('edit-planned-transaction')]
    public function openForEditPlanned(int $id): void
    {
        $planned = PlannedTransaction::query()
            ->where('user_id', auth()->id())
            ->find($id);

        if (! $planned) {
            return;
        }

        $this->resetForm();

        $this->editingPlannedTransactionId = $planned->id;
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
        $this->date = $planned->start_date->format('Y-m-d');
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
        $this->validate($this->formRules());

        if (! $this->resolveSave()) {
            return;
        }

        $this->showModal = false;
        $this->resetForm();
        $this->dispatch('transaction-saved');
    }

    public function updatedTransactionType(): void
    {
        if ($this->transactionType !== 'transfer' && ! $this->isBasiqTransaction) {
            $this->notes = '';
        }
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

        if ($transaction->source === TransactionSource::Basiq && $transaction->parent_transaction_id === null) {
            return;
        }

        DB::transaction(static function () use ($transaction): void {
            if ($transaction->transfer_pair_id) {
                Transaction::query()
                    ->where('id', $transaction->transfer_pair_id)
                    ->where('user_id', auth()->id())
                    ->delete();
            }

            $transaction->delete();
        });

        $this->showModal = false;
        $this->resetForm();
        $this->dispatch('transaction-saved');
    }

    public function render(): View
    {
        $accounts = auth()->user()
            ->accounts()
            ->active()
            ->visible()
            ->orderBy('name')
            ->get();

        $categories = Category::query()
            ->visible()
            ->with(['parent', 'parent.parent'])
            ->orderBy('name')
            ->get();

        return view('livewire.transaction-modal', [
            'accounts' => $accounts,
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
     * @throws Throwable
     */
    private function resolveSave(): bool
    {
        if ($this->editingTransactionId && $this->mode === 'plan' && ! $this->isBasiqTransaction) {
            return $this->convertEnteredToPlanned();
        }

        if ($this->editingPlannedTransactionId && $this->mode === 'enter') {
            return $this->convertPlannedToEntered();
        }

        if ($this->editingPlannedTransactionId) {
            return $this->updatePlannedTransaction();
        }

        if ($this->editingTransactionId) {
            if ($this->isBasiqTransaction) {
                return $this->updateTransaction();
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

        Transaction::query()->create([
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
        ]);

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

        DB::transaction(function () use ($parsed): void {
            $shared = [
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

            $debit->update(['transfer_pair_id' => $credit->id]);
            $credit->update(['transfer_pair_id' => $debit->id]);
        });

        return true;
    }

    private function createPlannedTransaction(): bool
    {
        $parsed = AmountParser::parse($this->descriptionInput);

        if ($parsed->amount <= 0) {
            $this->addError('descriptionInput', __('The amount must be greater than zero.'));

            return false;
        }

        $direction = match ($this->transactionType) {
            'income' => TransactionDirection::Credit,
            default => TransactionDirection::Debit,
        };

        PlannedTransaction::query()->create([
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
        ]);

        return true;
    }

    private function updatePlannedTransaction(): bool
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

        $direction = match ($this->transactionType) {
            'income' => TransactionDirection::Credit,
            default => TransactionDirection::Debit,
        };

        $planned->update([
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
        ]);

        return true;
    }

    private function updateTransaction(): bool
    {
        $resolved = $this->resolveTransactionWithParsedAmount();

        if ($resolved === false) {
            return false;
        }

        [$transaction, $parsed] = $resolved;

        if ($transaction->source === TransactionSource::Basiq) {
            $transaction->createChild([
                'category_id' => $this->categoryId,
                'notes' => $this->notes !== '' ? $this->notes : null,
                'clean_description' => $this->cleanDescription !== '' ? $this->cleanDescription : null,
            ]);

            return true;
        }

        $transaction->createChild([
            'account_id' => $this->accountId,
            'category_id' => $this->categoryId,
            'amount' => $parsed->amount,
            'direction' => $this->transactionType === 'expense'
                ? TransactionDirection::Debit
                : TransactionDirection::Credit,
            'description' => $parsed->description,
            'post_date' => $this->date,
            'notes' => $this->notes !== '' ? $this->notes : null,
        ]);

        return true;
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

            $debitChild->update(['transfer_pair_id' => $creditChild->id]);
            $creditChild->update(['transfer_pair_id' => $debitChild->id]);
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

            $debitChild->update(['transfer_pair_id' => $credit->id]);
            $credit->update(['transfer_pair_id' => $debitChild->id]);
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
            $debitSide->createChild([
                'account_id' => $this->accountId,
                'direction' => $direction,
                'amount' => $parsed->amount,
                'description' => $parsed->description,
                'post_date' => $this->date,
                'category_id' => $this->categoryId,
                'notes' => $this->notes !== '' ? $this->notes : null,
                'transfer_pair_id' => null,
            ]);

            $creditSide->delete();
        });

        return true;
    }

    /**
     * @throws Throwable
     */
    private function convertEnteredToPlanned(): bool
    {
        $transaction = Transaction::query()
            ->where('user_id', auth()->id())
            ->find($this->editingTransactionId);

        if (! $transaction) {
            return false;
        }

        $parsed = AmountParser::parse($this->descriptionInput);

        if ($parsed->amount <= 0) {
            $this->addError('descriptionInput', __('The amount must be greater than zero.'));

            return false;
        }

        $direction = match ($this->transactionType) {
            'income' => TransactionDirection::Credit,
            default => TransactionDirection::Debit,
        };

        DB::transaction(function () use ($transaction, $parsed, $direction): void {
            PlannedTransaction::query()->create([
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
            ]);

            $this->softDeleteWithAncestors($transaction);
        });

        return true;
    }

    /**
     * @throws Throwable
     */
    private function convertPlannedToEntered(): bool
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

        DB::transaction(function () use ($planned, $parsed): void {
            if ($this->transactionType === 'transfer') {
                $shared = [
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

                $debit->update(['transfer_pair_id' => $credit->id]);
                $credit->update(['transfer_pair_id' => $debit->id]);
            } else {
                Transaction::query()->create([
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
                ]);
            }

            $planned->delete();
        });

        return true;
    }

    /** @return array{Transaction, AmountParseResult}|false */
    private function resolveTransactionWithParsedAmount(): array|false
    {
        $transaction = Transaction::query()
            ->where('user_id', auth()->id())
            ->find($this->editingTransactionId);

        if (! $transaction) {
            return false;
        }

        $parsed = AmountParser::parse($this->descriptionInput);

        if ($parsed->amount <= 0) {
            $this->addError('descriptionInput', __('The amount must be greater than zero.'));

            return false;
        }

        return [$transaction, $parsed];
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

    private function isTransfer(): bool
    {
        return $this->transactionType === 'transfer';
    }

    private function softDeleteWithAncestors(Transaction $transaction): void
    {
        $current = $transaction;

        while ($current) {
            if ($current->transfer_pair_id) {
                Transaction::query()
                    ->where('id', $current->transfer_pair_id)
                    ->where('user_id', auth()->id())
                    ->delete();
            }

            $parentId = $current->parent_transaction_id;
            $current->delete();

            $current = $parentId
                ? Transaction::withTrashed()
                    ->where('user_id', auth()->id())
                    ->find($parentId)
                : null;
        }
    }

    private function resetForm(): void
    {
        $this->editingTransactionId = null;
        $this->editingPlannedTransactionId = null;
        $this->isBasiqTransaction = false;
        $this->transactionType = 'expense';
        $this->descriptionInput = '';
        $this->accountId = null;
        $this->categoryId = null;
        $this->date = '';
        $this->notes = '';
        $this->cleanDescription = '';
        $this->transferToAccountId = null;
        $this->originalWasTransfer = false;
        $this->mode = 'enter';
        $this->frequency = 'every-month';
        $this->untilType = 'always';
        $this->untilDate = null;
        $this->resetValidation();
    }

    /** @return array<string, mixed> */
    private function formRules(): array
    {
        $rules = [
            'mode' => ['required', Rule::in(['enter', 'plan'])],
            'transactionType' => ['required', Rule::in(['expense', 'income', 'transfer'])],
            'descriptionInput' => ['required', 'string', 'max:255'],
            'accountId' => [
                'required',
                Rule::exists('accounts', 'id')->where('user_id', auth()->id()),
            ],
            'categoryId' => [
                'nullable',
                Rule::exists('categories', 'id')->where('is_hidden', 0),
            ],
            'date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'cleanDescription' => ['nullable', 'string', 'max:255'],
            'transferToAccountId' => $this->isTransfer()
                ? [
                    'required',
                    Rule::exists('accounts', 'id')->where('user_id', auth()->id()),
                    Rule::notIn([$this->accountId]),
                ]
                : ['nullable'],
        ];

        if ($this->mode === 'plan') {
            $rules['transactionType'] = ['required', Rule::in(['expense', 'income', 'transfer'])];
            $rules['frequency'] = ['required', Rule::in(array_column(RecurrenceFrequency::cases(), 'value'))];
            $rules['untilType'] = ['required', Rule::in(['always', 'until-date'])];
            $rules['untilDate'] = $this->untilType === 'until-date'
                ? ['required', 'date_format:Y-m-d', 'after_or_equal:date']
                : ['nullable'];
        }

        return $rules;
    }
}
