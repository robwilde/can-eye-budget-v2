<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Contracts\AssistantWriteTool;
use App\Http\Resources\Api\BudgetResource;
use App\Models\Budget;
use App\Models\User;
use App\Services\Budgets\BudgetPlanManager;

final class UpdateBudget implements AssistantWriteTool
{
    use WritesUserData;

    public function __construct(private readonly BudgetPlanManager $plans) {}

    public function name(): string
    {
        return 'update_budget';
    }

    public function description(): string
    {
        return 'Changes one budget line the way the budget page does: set its allocation in cents (0 or more) and/or turn rollover on or off. An allocation change also moves the parent category lines, exactly as on the budget page.';
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
                'budget_id' => ['type' => 'integer'],
                'amount' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Allocation in cents.'],
                'rollover_enabled' => ['type' => 'boolean'],
            ],
            'required' => ['budget_id'],
        ];
    }

    public function handle(User $user, array $arguments): array
    {
        $data = $this->validated($arguments, [
            'budget_id' => ['required', 'integer', 'min:1'],
            'amount' => ['sometimes', 'integer', 'min:0', 'max:99999999999'],
            'rollover_enabled' => ['sometimes', 'boolean'],
        ]);

        if (is_string($data)) {
            return ['error' => $data];
        }

        if (! isset($data['amount']) && ! isset($data['rollover_enabled'])) {
            return ['error' => 'Nothing to change: give an amount or rollover_enabled.'];
        }

        return $this->attempt(function () use ($user, $data): array {
            $line = Budget::query()->where('user_id', $user->id)->findOrFail((int) $data['budget_id']);

            if (isset($data['amount'])) {
                $this->plans->setAllocation($user, $line, (int) $data['amount']);
            }

            if (isset($data['rollover_enabled'])) {
                $this->plans->setRollover($user, $line->refresh(), (bool) $data['rollover_enabled']);
            }

            $this->audited($user, 'budget', $line->id);

            return ['data' => $this->present([$line->refresh()], BudgetResource::class)[0]];
        });
    }
}
