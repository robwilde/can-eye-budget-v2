<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\TransactionResource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class TransactionController extends OwnedReadController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->owner($request);

        $filters = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'account_id' => ['sometimes', 'integer', Rule::exists(Account::class, 'id')->where('user_id', $user->id)],
            'category_id' => ['sometimes', 'integer', Rule::exists(Category::class, 'id')->where('user_id', $user->id)],
        ]);

        $query = Transaction::query()
            ->where('user_id', $user->id)
            ->current()
            ->with('splits')
            ->when(isset($filters['from']), fn ($q) => $q->whereDate('post_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate('post_date', '<=', $filters['to']))
            ->when(isset($filters['account_id']), fn ($q) => $q->where('account_id', $filters['account_id']))
            ->when(isset($filters['category_id']), fn ($q) => $q->where(fn ($inner) => $inner
                ->where('category_id', $filters['category_id'])
                ->orWhereHas('splits', fn ($splits) => $splits->where('category_id', $filters['category_id']))))
            ->orderByDesc('post_date')
            ->orderByDesc('id');

        return TransactionResource::collection($query->paginate($this->perPage($request))->withQueryString());
    }

    public function show(Request $request, int $id): TransactionResource
    {
        return new TransactionResource(
            Transaction::query()->where('user_id', $this->owner($request)->id)->current()->with('splits')->findOrFail($id),
        );
    }
}
