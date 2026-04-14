<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Models\Transaction;
use App\Models\UserRule;
use BackedEnum;

final readonly class RuleEvaluator
{
    public function matches(Transaction $transaction, UserRule $rule): bool
    {
        $triggers = $rule->triggers;

        if ($triggers === []) {
            return false;
        }

        if ($rule->strict_mode) {
            return array_all($triggers, fn ($trigger) => $this->evaluateTrigger($transaction, $trigger));
        }

        return array_any($triggers, fn ($trigger) => $this->evaluateTrigger($transaction, $trigger));
    }

    /** @param  array<string, string>  $trigger */
    private function evaluateTrigger(Transaction $transaction, array $trigger): bool
    {
        $field = RuleTriggerField::tryFrom($trigger['field'] ?? '');
        $operator = RuleTriggerOperator::tryFrom($trigger['operator'] ?? '');

        if ($field === null || $operator === null) {
            return false;
        }

        $expected = $trigger['value'] ?? '';

        if ($operator->requiresValue() && $expected === '') {
            return false;
        }

        $actual = $this->resolveFieldValue($transaction, $field);

        return $this->compare($actual, $operator, $expected, $field);
    }

    private function resolveFieldValue(Transaction $transaction, RuleTriggerField $field): mixed
    {
        $raw = $transaction->{$field->value};

        if ($raw instanceof BackedEnum) {
            return $raw->value;
        }

        return $raw;
    }

    private function compare(mixed $actual, RuleTriggerOperator $operator, string $expected, RuleTriggerField $field): bool
    {
        if ($operator === RuleTriggerOperator::IsEmpty) {
            return $actual === null || $actual === '';
        }

        if ($operator === RuleTriggerOperator::IsNotEmpty) {
            return $actual !== null && $actual !== '';
        }

        if ($field === RuleTriggerField::Amount) {
            return $this->compareNumeric((int) $actual, $operator, (int) $expected);
        }

        if (in_array($field, [RuleTriggerField::AccountId, RuleTriggerField::CategoryId], true)) {
            return $this->compareId($actual, $operator, $expected);
        }

        $actualStr = (string) ($actual ?? '');

        return match ($operator) {
            RuleTriggerOperator::Contains => mb_stripos($actualStr, $expected) !== false,
            RuleTriggerOperator::NotContains => mb_stripos($actualStr, $expected) === false,
            RuleTriggerOperator::Equals, RuleTriggerOperator::Is => mb_strtolower($actualStr) === mb_strtolower($expected),
            RuleTriggerOperator::NotEquals, RuleTriggerOperator::IsNot => mb_strtolower($actualStr) !== mb_strtolower($expected),
            RuleTriggerOperator::StartsWith => str_starts_with(mb_strtolower($actualStr), mb_strtolower($expected)),
            RuleTriggerOperator::EndsWith => str_ends_with(mb_strtolower($actualStr), mb_strtolower($expected)),
            default => false,
        };
    }

    private function compareNumeric(int $actual, RuleTriggerOperator $operator, int $expected): bool
    {
        return match ($operator) {
            RuleTriggerOperator::Equals, RuleTriggerOperator::Is => $actual === $expected,
            RuleTriggerOperator::NotEquals, RuleTriggerOperator::IsNot => $actual !== $expected,
            RuleTriggerOperator::GreaterThan => $actual > $expected,
            RuleTriggerOperator::LessThan => $actual < $expected,
            RuleTriggerOperator::GreaterThanOrEqual => $actual >= $expected,
            RuleTriggerOperator::LessThanOrEqual => $actual <= $expected,
            default => false,
        };
    }

    private function compareId(mixed $actual, RuleTriggerOperator $operator, string $expected): bool
    {
        return match ($operator) {
            RuleTriggerOperator::Equals, RuleTriggerOperator::Is => (int) $actual === (int) $expected,
            RuleTriggerOperator::NotEquals, RuleTriggerOperator::IsNot => (int) $actual !== (int) $expected,
            default => false,
        };
    }
}
