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

    public string $transactionType = 'expense';

    public string $descriptionInput = '';

    public ?int $accountId = null;

    public ?int $categoryId = null;

    public string $date = '';

    #[On('open-transaction-modal')]
    public function openForAdd(string $date): void
    {
        $this->resetForm();
        $this->date = $date;
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate($this->formRules());

        $parsed = AmountParser::parse($this->descriptionInput);

        if ($parsed->amount <= 0) {
            $this->addError('descriptionInput', __('The amount must be greater than zero.'));

            return;
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
        ]);

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

    private function resetForm(): void
    {
        $this->transactionType = 'expense';
        $this->descriptionInput = '';
        $this->accountId = null;
        $this->categoryId = null;
        $this->date = '';
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
        ];
    }
}
