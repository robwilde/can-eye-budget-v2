<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\PlannedTransactionStatus;
use App\Enums\PlannedTransactionType;
use App\Enums\PlanRefusal;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Exceptions\PlanWriteRefusedException;
use App\Http\Resources\Api\PlannedTransactionResource;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Recurring\PlanWriter;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
    public function store(Request $request, PlanWriter $writer): JsonResponse
    {
        $user = $this->recurringOwner($request, $writer);

        $attributes = $this->attributes($request, $user, null);

        $plan = $this->written(static fn (): PlannedTransaction => $writer->create($user, $attributes));

        return new PlannedTransactionResource($plan->refresh())->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Changes a plan; PUT and PATCH both accept a partial body. A plan's status can only be set to active or inactive.
     *
     * @throws Throwable
     */
    public function update(Request $request, int $id, PlanWriter $writer): PlannedTransactionResource
    {
        $user = $this->recurringOwner($request, $writer);

        $plan = $this->ownedPlan($user, $id, $writer);

        $attributes = $this->attributes($request, $user, $plan);

        $updated = $this->written(static fn (): PlannedTransaction => $writer->update($user, $plan, $attributes));

        return new PlannedTransactionResource($updated->refresh());
    }

    /**
     * @throws Throwable
     */
    public function destroy(Request $request, int $id, PlanWriter $writer): Response
    {
        $user = $this->recurringOwner($request, $writer);

        $plan = $this->ownedPlan($user, $id, $writer);

        $this->written(static fn () => $writer->discard($user, $plan));

        return response()->noContent();
    }

    private function recurringOwner(Request $request, PlanWriter $writer): User
    {
        $user = $this->owner($request);

        $refusal = $writer->refusalFor($user);

        if ($refusal instanceof PlanRefusal) {
            $this->refused($refusal);
        }

        return $user;
    }

    private function ownedPlan(User $user, int $id, PlanWriter $writer): PlannedTransaction
    {
        $plan = PlannedTransaction::query()->where('user_id', $user->id)->visible()->findOrFail($id);

        $refusal = $writer->refusalFor($user, $plan);

        if ($refusal instanceof PlanRefusal) {
            $this->refused($refusal);
        }

        return $plan;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    private function written(Closure $write): mixed
    {
        try {
            return $write();
        } catch (PlanWriteRefusedException $exception) {
            $this->refused($exception->reason);
        }
    }

    /** The response a refusal becomes: a validation error on the field it concerns, or its own status. */
    private function refused(PlanRefusal $refusal): never
    {
        $field = match ($refusal) {
            PlanRefusal::UntrackedAccount => 'account_id',
            PlanRefusal::HiddenCategory => 'category_id',
            PlanRefusal::StartNotAfterToday => 'start_date',
            default => null,
        };

        if ($field !== null) {
            throw ValidationException::withMessages([$field => $refusal->message()]);
        }

        abort($refusal->status(), match ($refusal) {
            PlanRefusal::PayCycleIncome => 'Pay cycle income is managed elsewhere.',
            PlanRefusal::Duplicate => $refusal->message(),
            default => '',
        });
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
            'account_id' => [$presence, 'integer', Rule::exists(Account::class, 'id')->where('user_id', $user->id)],
            'category_id' => ['nullable', 'integer', Rule::exists(Category::class, 'id')->where('user_id', $user->id)],
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
