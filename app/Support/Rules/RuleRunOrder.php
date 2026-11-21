<?php

declare(strict_types=1);

namespace App\Support\Rules;

use App\Models\UserRule;
use App\Models\UserRuleGroup;

/**
 * The rules an on-demand run goes through, in priority order: every active rule in an active group
 * above the target, then the target; or every active rule when there is no target. Each rule has
 * its group loaded. Shared by the apply job and the apply confirmation, so the count a user is
 * shown is the list the job runs.
 */
final class RuleRunOrder
{
    /** @return list<UserRule> */
    public static function forUser(int $userId, ?UserRule $target = null): array
    {
        $rules = [];

        $groups = UserRuleGroup::query()
            ->where('user_id', $userId)
            ->ordered()
            ->with(['rules' => fn ($query) => $query->ordered()->orderBy('id')])
            ->orderBy('id')
            ->get();

        foreach ($groups as $group) {
            foreach ($group->rules as $rule) {
                if ($target instanceof UserRule && $rule->id === $target->id) {
                    $rule->setRelation('group', $group);
                    $rules[] = $rule;

                    return $rules;
                }

                if ($group->is_active && $rule->is_active) {
                    $rule->setRelation('group', $group);
                    $rules[] = $rule;
                }
            }
        }

        return $rules;
    }
}
