<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Contracts\AssistantWriteTool;
use App\Http\Resources\Api\RuleResource;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateRule implements AssistantWriteTool
{
    use DefinesRules;

    public function name(): string
    {
        return 'update_rule';
    }

    public function description(): string
    {
        return 'Edits a rule. Changing only the name or description keeps it as it was; changing its triggers, actions, strict_mode or effective_from switches the rule OFF so the user can check it before it runs again.';
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
                'rule_id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'triggers' => $this->triggersSchema(),
                'actions' => $this->actionsSchema(),
                'strict_mode' => ['type' => 'boolean'],
                'effective_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD.'],
            ],
            'required' => ['rule_id'],
        ];
    }

    public function handle(User $user, array $arguments): array
    {
        $data = $this->validated($arguments, [
            'rule_id' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:255'],
            'triggers' => ['sometimes', 'array'],
            'actions' => ['sometimes', 'array'],
            'strict_mode' => ['sometimes', 'boolean'],
            'effective_from' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        if (is_string($data)) {
            return ['error' => $data];
        }

        $changes = array_intersect_key($data, array_flip(['name', 'description', 'triggers', 'actions', 'strict_mode', 'effective_from']));

        if ($changes === []) {
            return ['error' => 'Nothing to change.'];
        }

        return $this->attempt(function () use ($user, $data, $changes): array {
            $rule = $this->ownedRule($user, $data['rule_id']);

            $triggers = $changes['triggers'] ?? $rule->triggers;
            $actions = $changes['actions'] ?? $rule->actions;

            if (isset($changes['triggers']) || isset($changes['actions'])) {
                $problem = $this->definitionProblem($user, $triggers, $actions);

                if ($problem !== null) {
                    return ['error' => $problem];
                }
            }

            if (isset($changes['actions'])) {
                $changes['actions'] = $this->normalisedActions($changes['actions']);
            }

            DB::transaction(function () use ($user, $rule, $changes): void {
                $locked = $rule->newQuery()->where('user_id', $user->id)->lockForUpdate()->findOrFail($rule->id);
                $locked->fill($changes);

                $definitionChanged = $locked->isDirty(['triggers', 'actions', 'strict_mode', 'effective_from']);

                if ($definitionChanged) {
                    $locked->is_active = false;
                }

                $locked->save();
            });

            $this->audited($user, 'rule', $rule->id);

            return ['data' => $this->present([$rule->refresh()->load('group')], RuleResource::class)[0]];
        });
    }
}
