<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\PlannedTransactionStatus;
use App\Enums\PlannedTransactionType;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Http\Resources\Api\PlannedTransactionResource;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Recurring\PlanDuplicateGuard;
use App\Services\Recurring\RecurringGate;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

final class ManagePlannedTransactionController extends OwnedReadController
{
    public const int MAX_AMOUNT_CENTS = 999_999_999_999;

    /**
     * Declares a recurring plan; answers 404 when the user has recurring switched off and 409 when the user already
     * has a plan with the same account, direction and amount and the same description (ignoring case).
     *
     * @throws Throwable
     */
    public function store(Request $request, PlanDuplicateGuard $guard): JsonResponse
    {
        $user = $this->recurringOwner($request);

        $attributes = $this->attributes($request, $user, null);

        $plan = DB::transaction(function () use ($user, $guard, $attributes): PlannedTransaction {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            abort_if(
                $guard->exists($user, $attributes['account_id'], $attributes['amount'], $attributes['direction'], null, $attributes['description']),
                Response::HTTP_CONFLICT,
                'A plan like this already exists.',
            );

            return PlannedTransaction::query()->create(['user_id' => $user->id, ...$attributes]);
        });

        return new PlannedTransactionResource($plan->refresh())->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Changes a plan; PUT and PATCH both accept a partial body. A plan's status can only be set to active or inactive.
     *
     * @throws Throwable
     */
    public function update(Request $request, int $id): PlannedTransactionResource
    {
        $user = $this->recurringOwner($request);

        $plan = $this->ownedPlan($user, $id);

        $attributes = $this->attributes($request, $user, $plan);

        DB::transaction(fn (): bool => $plan->update($attributes));

        return new PlannedTransactionResource($plan->refresh());
    }

    /**
     * @throws Throwable
     */
    public function destroy(Request $request, int $id): Response
    {
        $user = $this->recurringOwner($request);

        $plan = $this->ownedPlan($user, $id);

        DB::transaction(fn () => $plan->discard());

        return response()->noContent();
    }

    private function recurringOwner(Request $request): User
    {
        $user = $this->owner($request);

        abort_unless(RecurringGate::enabled($user), Response::HTTP_NOT_FOUND);

        return $user;
    }

    private function ownedPlan(User $user, int $id): PlannedTransaction
    {
        $plan = PlannedTransaction::query()->where('user_id', $user->id)->visible()->findOrFail($id);

        abort_if($plan->is_pay_cycle_income === true, Response::HTTP_FORBIDDEN, 'Pay cycle income is managed elsewhere.');

        return $plan;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Request $request, User $user, ?PlannedTransaction $plan): array
    {
        $presence = $plan === null ? 'required' : 'sometimes';

        $frequencyInput = $request->input('frequency', $plan?->frequency->value);
        $directionInput = $request->input('direction', $plan?->direction->value);
        $frequency = is_string($frequencyInput) ? RecurrenceFrequency::tryFrom($frequencyInput) : null;
        $direction = is_string($directionInput) ? TransactionDirection::tryFrom($directionInput) : null;
        $isTransfer = $plan?->transfer_to_account_id !== null;

        $validated = $request->validate([
            'account_id' => [$presence, 'integer', Rule::exists(Account::class, 'id')->where(fn (Builder $query): Builder => $query->where('user_id', $user->id)->where('is_tracked', true))],
            'category_id' => ['nullable', 'integer', Rule::exists(Category::class, 'id')->where(fn (Builder $query): Builder => $query->where('user_id', $user->id)->where('is_hidden', false))],
            'amount' => [$presence, 'integer', 'min:1', 'max:'.self::MAX_AMOUNT_CENTS],
            'direction' => [$presence, Rule::enum(TransactionDirection::class)],
            'type' => ['sometimes', 'string', $this->typeRule($direction, $isTransfer)],
            'description' => [$presence, 'string', 'max:255', 'filled'],
            'start_date' => [$presence, 'date_format:Y-m-d', ...($plan?->until_date !== null && ! $request->has('until_date') ? ['before_or_equal:'.$plan->until_date->toDateString()] : [])],
            'frequency' => [$presence, Rule::in(array_map(static fn (RecurrenceFrequency $case): string => $case->value, RecurrenceFrequency::pickable()))],
            'recurrence_interval' => ['sometimes', 'integer', 'between:1,'.($frequency?->maxInterval() ?? 1)],
            'until_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.($plan === null || $request->has('start_date') ? 'start_date' : $plan->start_date->toDateString())],
            'autopay' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::enum(PlannedTransactionStatus::class)->only([PlannedTransactionStatus::Active, PlannedTransactionStatus::Inactive])],
        ]);

        return $this->mapped($validated, $plan, $frequency, $direction, $isTransfer);
    }

    private function typeRule(?TransactionDirection $direction, bool $isTransfer): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($direction, $isTransfer): void {
            $type = is_string($value) ? PlannedTransactionType::tryFrom($value) : null;

            if ($isTransfer || ($type !== null && $direction !== null && in_array($type, PlannedTransactionType::selectableFor($direction), true))) {
                return;
            }

            $fail(__('The selected type is not valid for this direction.'));
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function mapped(array $validated, ?PlannedTransaction $plan, ?RecurrenceFrequency $frequency, ?TransactionDirection $direction, bool $isTransfer): array
    {
        $attributes = array_intersect_key($validated, array_flip(['account_id', 'category_id', 'amount', 'description', 'start_date', 'until_date', 'autopay', 'notes']));

        foreach (['account_id', 'category_id', 'amount'] as $integer) {
            if (isset($attributes[$integer])) {
                $attributes[$integer] = (int) $attributes[$integer];
            }
        }

        if ($plan === null) {
            $attributes += ['autopay' => false, 'status' => PlannedTransactionStatus::Active, 'schedule_pinned' => true];
        }

        if (isset($validated['status'])) {
            $attributes['status'] = PlannedTransactionStatus::from($validated['status']);
        }

        if ($direction !== null && ($plan === null || array_key_exists('direction', $validated))) {
            $attributes['direction'] = $direction;
        }

        if ($frequency !== null && ($plan === null || array_key_exists('frequency', $validated) || array_key_exists('recurrence_interval', $validated))) {
            $attributes['frequency'] = $frequency;

            if ($plan !== null && $frequency !== $plan->frequency) {
                $attributes['days_of_month'] = null;
            }
            $attributes['recurrence_interval'] = $frequency->usesInterval()
                ? (int) ($validated['recurrence_interval'] ?? ($plan?->frequency === $frequency ? $plan->recurrence_interval : 1))
                : 1;
        }

        if ($plan !== null && (($attributes['frequency'] ?? $plan->frequency) !== $plan->frequency || ($attributes['recurrence_interval'] ?? $plan->recurrence_interval) !== $plan->recurrence_interval)) {
            $attributes['schedule_pinned'] = true;
        }

        if ($direction !== null && ! $isTransfer && ($plan === null || array_key_exists('type', $validated) || array_key_exists('direction', $validated))) {
            $attributes['type'] = PlannedTransactionType::resolve(
                PlannedTransactionType::tryFrom((string) ($validated['type'] ?? $plan?->type->value)),
                $direction,
                false,
            );
        }

        return $attributes;
    }
}
