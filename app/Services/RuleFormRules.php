<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Support\Rules\RegexPattern;
use App\Support\Rules\RuleTriggers;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The validation a rule's conditions and actions must pass before they are stored, shared by the
 * rule form and the rule importer so a file can never save what the form would refuse. Every
 * reference (tag, account) is checked against the given user's own records.
 */
final class RuleFormRules
{
    private const array TAG_OPERATORS = [
        RuleTriggerOperator::Equals,
        RuleTriggerOperator::NotEquals,
        RuleTriggerOperator::IsEmpty,
        RuleTriggerOperator::IsNotEmpty,
    ];

    /**
     * Rules for data keyed `triggers` and `actions`.
     *
     * @param  array<int|string, mixed>  $triggers
     * @param  array<int|string, mixed>  $actions
     * @param  Closure(string, mixed, Closure): void|null  $actionTypeGuard  extra check on each action type
     * @return array<string, array<int, mixed>>
     */
    public function forDraft(array $triggers, array $actions, int $userId, ?Closure $actionTypeGuard = null): array
    {
        $rules = [
            'triggers' => ['required', 'array', 'min:1', $this->hasCondition()],
            'actions' => ['required', 'array', 'min:1', $this->distinctActionTypes()],
            'actions.*.type' => ['required', Rule::in(array_column(RuleActionType::cases(), 'value')), ...($actionTypeGuard === null ? [] : [$actionTypeGuard])],
        ];

        $groupsSeen = 0;

        foreach (array_keys($triggers) as $index) {
            $entry = $triggers[$index];

            if (! RuleTriggers::isGroup($entry)) {
                $rules = [...$rules, ...$this->conditionRules("triggers.{$index}", is_array($entry) ? $entry : [], $userId)];

                continue;
            }

            if (++$groupsSeen > 1) {
                $rules["triggers.{$index}"] = [$this->failing('Only one condition group is allowed.')];
            }

            $rules["triggers.{$index}.match"] = ['required', Rule::in([RuleTriggers::MATCH_ALL, RuleTriggers::MATCH_ANY])];
            $rules["triggers.{$index}.conditions"] = ['array'];

            foreach (array_keys(is_array($entry['conditions'] ?? null) ? $entry['conditions'] : []) as $conditionIndex) {
                $condition = $entry['conditions'][$conditionIndex];

                if (RuleTriggers::isGroup($condition)) {
                    $rules["triggers.{$index}.conditions.{$conditionIndex}"] = [$this->failing('Compound conditions cannot be nested')];

                    continue;
                }

                $rules = [...$rules, ...$this->conditionRules("triggers.{$index}.conditions.{$conditionIndex}", is_array($condition) ? $condition : [], $userId)];
            }
        }

        // Build the value rule per action index: value-requiring types must have
        // a non-empty value (required is implicit so it catches empty strings),
        // while valueless actions (e.g. fold into parent) accept an empty value.
        // A clean description is written to a 255-character column.
        foreach (array_keys($actions) as $index) {
            $action = is_array($actions[$index]) ? $actions[$index] : [];
            $typeValue = $action['type'] ?? '';
            $type = is_string($typeValue) ? RuleActionType::tryFrom($typeValue) : null;

            if ($type === RuleActionType::AddTags) {
                $rules["actions.{$index}.tag_ids"] = ['required', 'array', 'min:1'];
                $rules["actions.{$index}.tag_ids.*"] = ['distinct', Rule::exists('tags', 'id')->where('user_id', $userId)];
            }

            $rules["actions.{$index}.value"] = match (true) {
                $type !== null && ! $type->requiresValue() => ['nullable', 'string', $this->noNullBytes()],
                $type === RuleActionType::SetCleanDescription => ['required', 'string', 'max:255', $this->noNullBytes()],
                $type === RuleActionType::MarkAsTransfer => ['required', 'string', Rule::exists('accounts', 'id')->where('user_id', $userId)->whereIn('status', [AccountStatus::Active->value, AccountStatus::Available->value])],
                default => ['required', 'string', $this->noNullBytes()],
            };
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'triggers.*.value.required' => 'The value field is required for the selected operator.',
            'triggers.*.conditions.*.value.required' => 'The value field is required for the selected operator.',
        ];
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array<string, array<int, mixed>>
     */
    private function conditionRules(string $prefix, array $condition, int $userId): array
    {
        $operatorValue = $condition['operator'] ?? '';
        $operator = is_string($operatorValue) ? RuleTriggerOperator::tryFrom($operatorValue) : null;
        $isTag = ($condition['field'] ?? null) === RuleTriggerField::Tag->value;

        return [
            "{$prefix}.field" => ['required', Rule::in(array_column(RuleTriggerField::cases(), 'value'))],
            "{$prefix}.operator" => ['required', $isTag
                ? Rule::in(array_map(static fn (RuleTriggerOperator $o): string => $o->value, self::TAG_OPERATORS))
                : Rule::in(array_column(RuleTriggerOperator::cases(), 'value'))],
            "{$prefix}.value" => match (true) {
                $isTag && $operator?->requiresValue() === true => ['required', 'string', Rule::exists('tags', 'id')->where('user_id', $userId)],
                $isTag => ['nullable', 'string'],
                $operator === RuleTriggerOperator::MatchesRegex => ['required', 'string', $this->noNullBytes(), $this->safeRegexPattern()],
                $operator?->requiresValue() === true => ['required', 'string', $this->noNullBytes()],
                default => ['nullable', 'string', $this->noNullBytes()],
            },
        ];
    }

    private function failing(string $message): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($message): void {
            $fail($message);
        };
    }

    private function hasCondition(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_array($value) && RuleTriggers::flatten($value) === []) {
                $fail('Add at least one condition.');
            }
        };
    }

    private function noNullBytes(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && str_contains($value, "\0")) {
                $fail('The value cannot contain null bytes.');
            }
        };
    }

    private function safeRegexPattern(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            if (mb_strlen($value) > RegexPattern::MAX_LENGTH) {
                $fail('The pattern may not be longer than '.RegexPattern::MAX_LENGTH.' characters.');
            } elseif (RegexPattern::hasBackReference($value)) {
                $fail('The pattern cannot use back-references.');
            } elseif (! RegexPattern::compiles($value)) {
                $fail('The pattern is not a valid regular expression.');
            }
        };
    }

    private function distinctActionTypes(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            $types = array_filter(array_column($value, 'type'), static fn (mixed $type): bool => is_string($type) && $type !== '');

            if (count($types) !== count(array_unique($types))) {
                $fail('A rule cannot have two actions of the same type.');
            }
        };
    }
}
