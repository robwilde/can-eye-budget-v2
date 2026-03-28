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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

final class TransactionModal extends Component
{
    public bool $showModal = false;

    public ?int $editingTransactionId = null;

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

    #[On('open-transaction-modal')]
    public function openForAdd(string $date): void
    {
        $this->resetForm();
        $this->date = $date;
        $this->showModal = true;
    }

    #[On('edit-transaction')]
    public function openForEdit(int $id): void
    {
        $transaction = Transaction::query()
            ->where('user_id', auth()->id())
            ->find($id);

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

        $dollars = number_format($transaction->amount / 100, 2, '.', '');
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
        $this->transactionType = $planned->direction === TransactionDirection::Debit
            ? 'expense'
            : 'income';

        $dollars = number_format($planned->amount / 100, 2, '.', '');
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
        if ($this->editingPlannedTransactionId) {
            $this->mode = 'plan';
        }

        $this->validate($this->formRules());

        if ($this->editingPlannedTransactionId) {
            $saved = $this->updatePlannedTransaction();
        } elseif ($this->mode === 'plan') {
            $saved = $this->createPlannedTransaction();
        } elseif ($this->editingTransactionId) {
            $saved = $this->isTransfer()
                ? $this->updateTransfer()
                : $this->updateTransaction();
        } else {
            $saved = $this->transactionType === 'transfer'
                ? $this->createTransfer()
                : $this->createTransaction();
        }

        if (! $saved) {
            return;
        }

        $this->showModal = false;
        $this->resetForm();
        $this->dispatch('transaction-saved');
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

        if ($transaction->source === TransactionSource::Basiq) {
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

        PlannedTransaction::query()->create([
            'user_id' => auth()->id(),
            'account_id' => $this->accountId,
            'category_id' => $this->categoryId,
            'amount' => $parsed->amount,
            'direction' => $this->transactionType === 'expense'
                ? TransactionDirection::Debit
                : TransactionDirection::Credit,
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

        $planned->update([
            'account_id' => $this->accountId,
            'category_id' => $this->categoryId,
            'amount' => $parsed->amount,
            'direction' => $this->transactionType === 'expense'
                ? TransactionDirection::Debit
                : TransactionDirection::Credit,
            'description' => $parsed->description,
            'start_date' => $this->date,
            'frequency' => RecurrenceFrequency::from($this->frequency),
            'until_date' => $this->untilType === 'until-date' ? $this->untilDate : null,
        ]);

        return true;
    }

    private function updateTransaction(): bool
    {
        $transaction = Transaction::query()
            ->where('user_id', auth()->id())
            ->find($this->editingTransactionId);

        if (! $transaction) {
            return false;
        }

        if ($transaction->source === TransactionSource::Basiq) {
            $transaction->update([
                'category_id' => $this->categoryId,
                'notes' => $this->notes !== '' ? $this->notes : null,
                'clean_description' => $this->cleanDescription !== '' ? $this->cleanDescription : null,
            ]);

            return true;
        }

        $parsed = AmountParser::parse($this->descriptionInput);

        if ($parsed->amount <= 0) {
            $this->addError('descriptionInput', __('The amount must be greater than zero.'));

            return false;
        }

        $transaction->update([
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

        DB::transaction(function () use ($transaction, $pair, $parsed): void {
            $shared = [
                'category_id' => $this->categoryId,
                'amount' => $parsed->amount,
                'description' => $parsed->description,
                'post_date' => $this->date,
                'notes' => $this->notes !== '' ? $this->notes : null,
            ];

            $debitSide = $transaction->direction === TransactionDirection::Debit ? $transaction : $pair;
            $creditSide = $transaction->direction === TransactionDirection::Credit ? $transaction : $pair;

            $debitSide->update($shared + ['account_id' => $this->accountId]);
            $creditSide->update($shared + ['account_id' => $this->transferToAccountId]);
        });

        return true;
    }

    private function isTransfer(): bool
    {
        if (! $this->editingTransactionId) {
            return $this->transactionType === 'transfer';
        }

        return Transaction::query()
            ->where('id', $this->editingTransactionId)
            ->where('user_id', auth()->id())
            ->whereNotNull('transfer_pair_id')
            ->exists();
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
            $rules['transactionType'] = ['required', Rule::in(['expense', 'income'])];
            $rules['frequency'] = ['required', Rule::in(array_column(RecurrenceFrequency::cases(), 'value'))];
            $rules['untilType'] = ['required', Rule::in(['always', 'until-date'])];
            $rules['untilDate'] = $this->untilType === 'until-date'
                ? ['required', 'date_format:Y-m-d', 'after_or_equal:date']
                : ['nullable'];
        }

        return $rules;
    }
}
