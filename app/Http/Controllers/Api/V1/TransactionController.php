<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\TransactionDirection;
use App\Http\Resources\Api\TransactionResource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\Transactions\TransactionWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class TransactionController extends OwnedReadController
{
    private const int MAX_CENTS = 99_999_999_999;

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

    public function store(Request $request, TransactionWriter $writer): JsonResponse
    {
        $user = $this->owner($request);

        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists(Account::class, 'id')->where(fn ($query) => $query->where('user_id', $user->id)->where('is_tracked', true))],
            'amount' => ['required', 'integer', 'min:1', 'max:'.self::MAX_CENTS],
            'direction' => ['required', Rule::enum(TransactionDirection::class)],
            'post_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists(Category::class, 'id')->where(fn ($query) => $query->where('user_id', $user->id)->where('is_hidden', false))],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'excluded' => ['sometimes', 'boolean'],
        ]);

        $transaction = $writer->create($user, $data);

        return (new TransactionResource($transaction->load('splits')))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $id, TransactionWriter $writer): JsonResponse
    {
        $user = $this->owner($request);

        $changes = $request->validate([
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists(Category::class, 'id')->where(fn ($query) => $query->where('user_id', $user->id)->where('is_hidden', false))],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'excluded' => ['sometimes', 'boolean'],
            'amount' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_CENTS],
            'direction' => ['sometimes', Rule::enum(TransactionDirection::class)],
            'post_date' => ['sometimes', 'date_format:Y-m-d'],
            'description' => ['sometimes', 'string', 'max:255'],
        ]);

        return (new TransactionResource($writer->update($user, $id, $changes)->load('splits')))->response()->setStatusCode(200);
    }

    public function destroy(Request $request, int $id, TransactionWriter $writer): Response
    {
        $writer->delete($this->owner($request), $id);

        return response()->noContent();
    }

    public function show(Request $request, int $id): TransactionResource
    {
        return new TransactionResource(
            Transaction::query()->where('user_id', $this->owner($request)->id)->current()->with('splits')->findOrFail($id),
        );
    }
}
