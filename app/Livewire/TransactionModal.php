<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\AmountParser;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

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

        $this->resetForm();

        $this->editingTransactionId = $transaction->id;
        $this->isBasiqTransaction = $transaction->source === TransactionSource::Basiq;

        $this->transactionType = $transaction->direction === TransactionDirection::Debit
            ? 'expense'
            : 'income';

        $dollars = number_format($transaction->amount / 100, 2, '.', '');
        $description = $transaction->description ?? '';
        $this->descriptionInput = $description !== '' ? "{$dollars} {$description}" : $dollars;

        if ($this->isBasiqTransaction) {
            $this->cleanDescription = $transaction->clean_description ?? '';
        }

        $this->accountId = $transaction->account_id;
        $this->categoryId = $transaction->category_id;
        $this->date = $transaction->post_date->format('Y-m-d');
        $this->notes = $transaction->notes ?? '';

        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate($this->formRules());

        if ($this->editingTransactionId) {
            $saved = $this->updateTransaction();
        } else {
            $saved = $this->createTransaction();
        }

        if (! $saved) {
            return;
        }

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

    private function resetForm(): void
    {
        $this->editingTransactionId = null;
        $this->isBasiqTransaction = false;
        $this->transactionType = 'expense';
        $this->descriptionInput = '';
        $this->accountId = null;
        $this->categoryId = null;
        $this->date = '';
        $this->notes = '';
        $this->cleanDescription = '';
        $this->resetValidation();
    }

    /** @return array<string, mixed> */
    private function formRules(): array
    {
        return [
            'transactionType' => ['required', Rule::in(['expense', 'income'])],
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
        ];
    }
}
