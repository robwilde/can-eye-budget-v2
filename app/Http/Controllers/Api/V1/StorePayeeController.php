<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\BudgetTag;
use App\Enums\PayeeStatus;
use App\Http\Resources\Api\PayeeResource;
use App\Models\Category;
use App\Models\Payee;
use App\Models\User;
use App\Services\Payees\PayeeConfirmer;
use App\Support\Recurring\MerchantSignature;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class StorePayeeController extends OwnedReadController
{
    private const int TRANSACTION_ATTEMPTS = 3;

    /**
     * Records the user's category for a merchant through PayeeConfirmer: one rule plus the matching history.
     * The key is derived from merchant_key (or merchant_name) the way transactions derive theirs. A merchant
     * with no payee yet is created and answered 201; one that already has a payee (pending, confirmed or
     * dismissed) is confirmed again with the new category and answered 200, so the call is idempotent per key.
     *
     * @throws Throwable
     */
    public function __invoke(Request $request, PayeeConfirmer $confirmer): JsonResponse
    {
        $user = $this->owner($request);

        $validated = $request->validate([
            'merchant_name' => ['required', 'string', 'max:255'],
            'merchant_key' => ['nullable', 'string', 'max:255'],
            'category_id' => ['required', 'integer', Rule::exists(Category::class, 'id')->where(fn (Builder $query): Builder => $query->where('user_id', $user->id)->where('is_hidden', false))],
            'budget_tag' => ['nullable', Rule::enum(BudgetTag::class)],
        ]);

        $merchantName = mb_trim($validated['merchant_name']);
        $merchantKey = MerchantSignature::for(mb_trim($validated['merchant_key'] ?? $merchantName));

        if ($merchantKey === '' || $merchantName === '') {
            throw ValidationException::withMessages(['merchant_name' => __('The merchant name must contain a name.')]);
        }

        $tag = isset($validated['budget_tag']) ? BudgetTag::from($validated['budget_tag']) : null;

        try {
            [$payee, $created] = $this->record($user, $confirmer, $merchantKey, $merchantName, (int) $validated['category_id'], $tag);
        } catch (UniqueConstraintViolationException) {
            [$payee, $created] = $this->record($user, $confirmer, $merchantKey, $merchantName, (int) $validated['category_id'], $tag);
        }

        return new PayeeResource($payee)->response()->setStatusCode($created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /**
     * @return array{0: Payee, 1: bool}
     *
     * @throws Throwable
     */
    private function record(User $user, PayeeConfirmer $confirmer, string $merchantKey, string $merchantName, int $categoryId, ?BudgetTag $tag): array
    {
        return DB::transaction(function () use ($user, $confirmer, $merchantKey, $merchantName, $categoryId, $tag): array {
            $payee = Payee::query()
                ->where('user_id', $user->id)
                ->where('merchant_key', $merchantKey)
                ->lockForUpdate()
                ->first();

            $created = $payee === null;

            $payee ??= Payee::query()->create([
                'user_id' => $user->id,
                'merchant_key' => $merchantKey,
                'merchant_name' => $merchantName,
                'status' => PayeeStatus::Pending,
            ]);

            return [$confirmer->confirm($user, $payee, $categoryId, $tag), $created];
        }, self::TRANSACTION_ATTEMPTS);
    }
}
