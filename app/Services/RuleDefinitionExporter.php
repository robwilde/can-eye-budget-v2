<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;

/**
 * Writes a user's rule groups and rules as a versioned document that does not depend on any
 * database id: references are names, so another user can import it.
 */
final class RuleDefinitionExporter
{
    public const string FORMAT = 'can-eye-budget.rules';

    public const int VERSION = 1;

    /** @return array{format: string, version: int, groups: list<array<string, mixed>>} */
    public function export(User $user): array
    {
        $references = new RuleDefinitionReferences($user->id);

        $groups = UserRuleGroup::query()
            ->where('user_id', $user->id)
            ->ordered()
            ->orderBy('id')
            ->with(['rules' => fn ($query) => $query->ordered()->orderBy('id')])
            ->get();

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'groups' => $groups->map(static fn (UserRuleGroup $group): array => [
                'name' => $group->name,
                'description' => $group->description,
                'is_active' => $group->is_active,
                'stop_processing' => $group->stop_processing,
                'rules' => $group->rules->map(static fn (UserRule $rule): array => [
                    'name' => $rule->name,
                    'description' => $rule->description,
                    'is_active' => $rule->is_active,
                    'is_auto_apply' => $rule->is_auto_apply,
                    'strict_mode' => $rule->strict_mode,
                    'effective_from' => $rule->effective_from?->format('Y-m-d'),
                    'triggers' => $references->portableTriggers($rule->triggers),
                    'actions' => $references->portableActions($rule->actions),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    public function toJson(User $user): string
    {
        return json_encode($this->export($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
