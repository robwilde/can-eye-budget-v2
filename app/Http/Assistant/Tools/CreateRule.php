<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Contracts\AssistantWriteTool;
use App\Http\Resources\Api\RuleResource;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use Illuminate\Support\Facades\DB;

final class CreateRule implements AssistantWriteTool
{
    use DefinesRules;

    public function name(): string
    {
        return 'create_rule';
    }

    public function description(): string
    {
        return 'Creates a categorisation rule. It is ALWAYS saved switched off: the user turns it on in the rules page, or you can preview it with preview_rule and apply it to history with apply_rule.';
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
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'group_id' => ['type' => 'integer', 'description' => 'Rule group; defaults to the first group.'],
                'triggers' => $this->triggersSchema(),
                'actions' => $this->actionsSchema(),
                'strict_mode' => ['type' => 'boolean'],
                'effective_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD.'],
            ],
            'required' => ['name', 'triggers', 'actions'],
        ];
    }

    public function handle(User $user, array $arguments): array
    {
        $data = $this->validated($arguments, [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:255'],
            'group_id' => ['sometimes', 'integer', 'min:1'],
            'triggers' => ['required', 'array'],
            'actions' => ['required', 'array'],
            'strict_mode' => ['sometimes', 'boolean'],
            'effective_from' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        if (is_string($data)) {
            return ['error' => $data];
        }

        $problem = $this->definitionProblem($user, $data['triggers'], $data['actions']);

        if ($problem !== null) {
            return ['error' => $problem];
        }

        return $this->attempt(function () use ($user, $data): array {
            $groups = UserRuleGroup::query()->where('user_id', $user->id);
            $group = isset($data['group_id']) ? $groups->findOrFail((int) $data['group_id']) : $groups->ordered()->first();

            if ($group === null) {
                return ['error' => 'There is no rule group yet. Ask the user to create one on the rules page first.'];
            }

            $rule = DB::transaction(fn (): UserRule => UserRule::query()->create([
                'user_id' => $user->id,
                'user_rule_group_id' => $group->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'triggers' => $data['triggers'],
                'actions' => $this->normalisedActions($data['actions']),
                'strict_mode' => (bool) ($data['strict_mode'] ?? false),
                'is_auto_apply' => false,
                'is_active' => false,
                'effective_from' => $data['effective_from'] ?? null,
                'order' => ((int) UserRule::query()->where('user_rule_group_id', $group->id)->max('order')) + 1,
            ]));

            $this->audited($user, 'rule', $rule->id);

            return ['data' => $this->present([$rule->load('group')], RuleResource::class)[0]];
        });
    }
}
