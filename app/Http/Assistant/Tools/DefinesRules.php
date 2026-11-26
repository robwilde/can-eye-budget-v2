<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Models\User;
use App\Models\UserRule;
use App\Services\RuleFormRules;
use Closure;
use Illuminate\Support\Facades\Validator;

/**
 * What the rule tools share: the schema of a rule definition, the same validation the rules page applies, and the
 * user-scoped rule lookup.
 */
trait DefinesRules
{
    use WritesUserData;

    /**
     * @return array<string, mixed>
     */
    private function triggersSchema(): array
    {
        return [
            'type' => 'array',
            'description' => 'Conditions, all of which must match (each: field, operator, value). Amounts are in cents.',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'field' => ['type' => 'string', 'enum' => array_column(RuleTriggerField::cases(), 'value')],
                    'operator' => ['type' => 'string', 'enum' => array_column(RuleTriggerOperator::cases(), 'value')],
                    'value' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function actionsSchema(): array
    {
        return [
            'type' => 'array',
            'description' => 'What the rule does to a match (each: type and value, one action per type; add_tags takes tag_ids).',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => array_map(static fn (RuleActionType $type): string => $type->value, $this->allowedActionTypes())],
                    'value' => ['type' => 'string'],
                    'tag_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                ],
            ],
        ];
    }

    /**
     * @return list<RuleActionType>
     */
    private function allowedActionTypes(): array
    {
        return array_filter(RuleActionType::cases(), static fn (RuleActionType $type): bool => $type !== RuleActionType::SuggestCategoryWithAi);
    }

    /**
     * @param  array<string, mixed>  $triggers
     * @param  array<string, mixed>  $actions
     * @return string|null the first problem, or null when the definition is valid
     */
    private function definitionProblem(User $user, array $triggers, array $actions): ?string
    {
        $forms = app(RuleFormRules::class);
        $guard = static function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === RuleActionType::SuggestCategoryWithAi->value) {
                $fail('The AI categorise action is not available to the assistant.');
            }
        };

        $validator = Validator::make(['triggers' => $triggers, 'actions' => $actions], $forms->forDraft($triggers, $actions, $user->id, $guard), $forms->messages());

        return $validator->fails() ? 'Invalid rule: '.implode(' ', $validator->errors()->all()) : null;
    }

    /**
     * An add-tags action keeps its tag ids as integers, as the rules page stores it.
     *
     * @param  array<int, array<string, mixed>>  $actions
     * @return list<array<string, mixed>>
     */
    private function normalisedActions(array $actions): array
    {
        return array_values(array_map(static function (array $action): array {
            if (($action['type'] ?? null) !== RuleActionType::AddTags->value) {
                return $action;
            }

            $ids = is_array($action['tag_ids'] ?? null) ? $action['tag_ids'] : [];

            return ['type' => RuleActionType::AddTags->value, 'value' => '', 'tag_ids' => array_values(array_unique(array_map(intval(...), $ids)))];
        }, $actions));
    }

    private function ownedRule(User $user, mixed $id): UserRule
    {
        return UserRule::query()->where('user_id', $user->id)->findOrFail(is_numeric($id) ? (int) $id : 0);
    }
}
