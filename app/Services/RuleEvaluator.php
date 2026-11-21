<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Models\Transaction;
use App\Models\UserRule;
use App\Support\Rules\RegexPattern;
use App\Support\Rules\RuleTriggers;
use BackedEnum;

final readonly class RuleEvaluator
{
    public function matches(Transaction $transaction, UserRule $rule): bool
    {
        $triggers = array_values(array_filter($rule->triggers, static fn (mixed $entry): bool => ! RuleTriggers::isEmptyGroup($entry)));

        if ($triggers === []) {
            return false;
        }

        if ($rule->effective_from !== null && $transaction->post_date->toDateString() < $rule->effective_from->toDateString()) {
            return false;
        }

        if ($rule->strict_mode) {
            return array_all($triggers, fn ($entry) => $this->evaluateEntry($transaction, $entry));
        }

        return array_any($triggers, fn ($entry) => $this->evaluateEntry($transaction, $entry));
    }

    /** @param  array<string, mixed>  $entry */
    private function evaluateEntry(Transaction $transaction, array $entry): bool
    {
        if (! RuleTriggers::isGroup($entry)) {
            return $this->evaluateTrigger($transaction, $entry);
        }

        $conditions = RuleTriggers::conditionsOf($entry);

        if ($conditions === []) {
            return true;
        }

        return ($entry['match'] ?? RuleTriggers::MATCH_ALL) === RuleTriggers::MATCH_ANY
            ? array_any($conditions, fn ($condition) => $this->evaluateEntry($transaction, $condition))
            : array_all($conditions, fn ($condition) => $this->evaluateEntry($transaction, $condition));
    }

    /** @param  array<string, mixed>  $trigger */
    private function evaluateTrigger(Transaction $transaction, array $trigger): bool
    {
        $field = RuleTriggerField::tryFrom(is_string($trigger['field'] ?? null) ? $trigger['field'] : '');
        $operator = RuleTriggerOperator::tryFrom(is_string($trigger['operator'] ?? null) ? $trigger['operator'] : '');

        if ($field === null || $operator === null) {
            return false;
        }

        $expected = is_scalar($trigger['value'] ?? null) ? (string) $trigger['value'] : '';

        if ($operator->requiresValue() && $expected === '') {
            return false;
        }

        if ($field === RuleTriggerField::Tag) {
            return $this->compareTag($transaction, $operator, $expected);
        }

        $actual = $this->resolveFieldValue($transaction, $field);

        return $this->compare($actual, $operator, $expected, $field);
    }

    /**
     * A tag condition reads "has this tag": equals needs the tag on the transaction, not equals is
     * true without it (so an untagged transaction matches), and the empty operators test for any
     * tag at all. One answer per transaction, so a transaction carrying several tags is never
     * counted twice by an any group.
     */
    private function compareTag(Transaction $transaction, RuleTriggerOperator $operator, string $expected): bool
    {
        $tagIds = $transaction->loadMissing('tags')->tags->pluck('id')->all();
        $has = $expected !== '' && in_array((int) $expected, $tagIds, true);

        return match ($operator) {
            RuleTriggerOperator::Equals, RuleTriggerOperator::Is => $has,
            RuleTriggerOperator::NotEquals, RuleTriggerOperator::IsNot => ! $has,
            RuleTriggerOperator::IsEmpty => $tagIds === [],
            RuleTriggerOperator::IsNotEmpty => $tagIds !== [],
            default => false,
        };
    }

    private function resolveFieldValue(Transaction $transaction, RuleTriggerField $field): mixed
    {
        $raw = $transaction->getAttribute($field->value);

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
            RuleTriggerOperator::MatchesRegex => RegexPattern::matches($expected, $actualStr),
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
