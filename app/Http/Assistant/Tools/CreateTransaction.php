<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Contracts\AssistantWriteTool;
use App\Enums\TransactionDirection;
use App\Http\Resources\Api\TransactionResource;
use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use App\Services\Transactions\TransactionWriter;
use Illuminate\Validation\Rule;

final class CreateTransaction implements AssistantWriteTool
{
    use WritesUserData;

    private const int MAX_CENTS = 99_999_999_999;

    public function __construct(private readonly TransactionWriter $writer) {}

    public function name(): string
    {
        return 'create_transaction';
    }

    public function description(): string
    {
        return 'Enters a manual transaction on one of the user\'s tracked accounts. The amount is a positive integer in cents (100 = 1.00); the direction says whether it is a debit (money out) or a credit (money in).';
    }

    public function requiresPreview(): bool
    {
        return false;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account_id' => ['type' => 'integer'],
                'amount' => ['type' => 'integer', 'description' => 'Positive amount in cents.'],
                'direction' => ['type' => 'string', 'enum' => ['debit', 'credit']],
                'post_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD.'],
                'description' => ['type' => 'string'],
                'category_id' => ['type' => 'integer'],
                'notes' => ['type' => 'string'],
            ],
            'required' => ['account_id', 'amount', 'direction', 'post_date', 'description'],
        ];
    }

    public function handle(User $user, array $arguments): array
    {
        $data = $this->validated($arguments, [
            'account_id' => ['required', 'integer', Rule::exists(Account::class, 'id')->where(fn ($query) => $query->where('user_id', $user->id)->where('is_tracked', true))],
            'amount' => ['required', 'integer', 'min:1', 'max:'.self::MAX_CENTS],
            'direction' => ['required', Rule::enum(TransactionDirection::class)],
            'post_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'category_id' => ['sometimes', 'integer', Rule::exists(Category::class, 'id')->where(fn ($query) => $query->where('user_id', $user->id)->where('is_hidden', false))],
            'notes' => ['sometimes', 'string', 'max:1000'],
        ]);

        if (is_string($data)) {
            return ['error' => $data];
        }

        return $this->attempt(function () use ($user, $data): array {
            $transaction = $this->writer->create($user, $data);
            $this->audited($user, 'transaction', $transaction->id);

            return ['data' => $this->present([$transaction->load('splits')], TransactionResource::class)[0]];
        });
    }
}
