<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Contracts\AssistantTool;
use App\Http\Resources\Api\TransactionResource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Validation\Rule;

final class SearchTransactions implements AssistantTool
{
    use ReadsUserData;

    public const int PAGE_SIZE = 50;

    public function name(): string
    {
        return 'search_transactions';
    }

    public function description(): string
    {
        return "Searches the user's transactions, newest first, 50 per page. Amounts are integer cents (100 = 1.00). Filter by date range, account, category, payee text and absolute amount range.";
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
                'from' => ['type' => 'string', 'description' => 'First post date, YYYY-MM-DD.'],
                'to' => ['type' => 'string', 'description' => 'Last post date, YYYY-MM-DD.'],
                'account_id' => ['type' => 'integer'],
                'category_id' => ['type' => 'integer'],
                'payee' => ['type' => 'string', 'description' => 'Text found in the merchant name or description.'],
                'min_amount' => ['type' => 'integer', 'description' => 'Smallest absolute amount in cents.'],
                'max_amount' => ['type' => 'integer', 'description' => 'Largest absolute amount in cents.'],
                'page' => ['type' => 'integer', 'minimum' => 1],
            ],
        ];
    }

    public function handle(User $user, array $arguments): array
    {
        $filters = $this->validated($arguments, [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...(is_string($arguments['from'] ?? null) ? ['after_or_equal:from'] : [])],
            'account_id' => ['sometimes', 'integer', Rule::exists(Account::class, 'id')->where('user_id', $user->id)],
            'category_id' => ['sometimes', 'integer', Rule::exists(Category::class, 'id')->where('user_id', $user->id)],
            'payee' => ['sometimes', 'string', 'max:100'],
            'min_amount' => ['sometimes', 'integer', 'min:0'],
            'max_amount' => ['sometimes', 'integer', 'min:0', ...(filled($arguments['min_amount'] ?? null) ? ['gte:min_amount'] : [])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        if (is_string($filters)) {
            return ['error' => $filters];
        }

        $page = (int) ($filters['page'] ?? 1);

        $rows = Transaction::query()
            ->where('user_id', $user->id)
            ->current()
            ->with('splits')
            ->when(isset($filters['from']), fn ($q) => $q->whereDate('post_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate('post_date', '<=', $filters['to']))
            ->when(isset($filters['account_id']), fn ($q) => $q->where('account_id', $filters['account_id']))
            ->when(isset($filters['category_id']), fn ($q) => $q->where(fn ($inner) => $inner
                ->where('category_id', $filters['category_id'])
                ->orWhereHas('splits', fn ($splits) => $splits->where('category_id', $filters['category_id']))))
            ->when(isset($filters['payee']), function ($q) use ($filters): void {
                $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $filters['payee']).'%';

                $q->where(fn ($inner) => $inner
                    ->whereRaw("merchant_name LIKE ? ESCAPE '!'", [$needle])
                    ->orWhereRaw("description LIKE ? ESCAPE '!'", [$needle]));
            })
            ->when(isset($filters['min_amount']), fn ($q) => $q->whereRaw('ABS(amount) >= ?', [$filters['min_amount']]))
            ->when(isset($filters['max_amount']), fn ($q) => $q->whereRaw('ABS(amount) <= ?', [$filters['max_amount']]))
            ->orderByDesc('post_date')
            ->orderByDesc('id')
            ->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)
            ->get();

        return [
            'page' => $page,
            'has_more' => $rows->count() > self::PAGE_SIZE,
            'data' => $this->present($rows->take(self::PAGE_SIZE), TransactionResource::class),
        ];
    }
}
