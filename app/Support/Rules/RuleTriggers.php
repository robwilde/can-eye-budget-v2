<?php

declare(strict_types=1);

namespace App\Support\Rules;

use Closure;

/**
 * Reads a rule's stored triggers, which are a flat list of {field, operator, value} entries,
 * optionally with one entry {type: group, match: all|any, conditions: [...]}. Old rules hold
 * flat lists only, so no entry needs a type to stay valid.
 */
final class RuleTriggers
{
    public const string GROUP = 'group';

    public const string MATCH_ALL = 'all';

    public const string MATCH_ANY = 'any';

    public static function isGroup(mixed $entry): bool
    {
        return is_array($entry) && ($entry['type'] ?? null) === self::GROUP;
    }

    public static function isEmptyGroup(mixed $entry): bool
    {
        return self::isGroup($entry) && self::conditionsOf($entry) === [];
    }

    /**
     * @param  array<string, mixed>  $group
     * @return list<array<string, mixed>>
     */
    public static function conditionsOf(array $group): array
    {
        $conditions = $group['conditions'] ?? [];

        return is_array($conditions) ? array_values(array_filter($conditions, is_array(...))) : [];
    }

    /**
     * Whether any condition, at the top or inside a group, tests the given field.
     *
     * @param  array<int|string, mixed>  $triggers
     */
    public static function usesField(array $triggers, string $field): bool
    {
        return array_any(self::flatten($triggers), static fn (array $condition): bool => ($condition['field'] ?? null) === $field);
    }

    /**
     * Every {field, operator, value} entry, whether at the top or inside a group.
     *
     * @param  array<int|string, mixed>  $triggers
     * @return list<array<string, mixed>>
     */
    public static function flatten(array $triggers): array
    {
        $leaves = [];

        foreach ($triggers as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            array_push($leaves, ...(self::isGroup($entry) ? self::flatten(self::conditionsOf($entry)) : [$entry]));
        }

        return $leaves;
    }

    /**
     * @param  array<int|string, mixed>  $triggers
     * @param  Closure(array<string, mixed>): array<string, mixed>  $change
     * @return list<mixed>
     */
    public static function map(array $triggers, Closure $change): array
    {
        return array_values(array_map(static function (mixed $entry) use ($change): mixed {
            if (! is_array($entry)) {
                return $entry;
            }

            if (! self::isGroup($entry)) {
                return $change($entry);
            }

            return [...$entry, 'conditions' => self::map(self::conditionsOf($entry), $change)];
        }, $triggers));
    }

    /**
     * Drop every {field, operator, value} entry the predicate selects. A group the removal
     * empties is dropped with them; a group that was already empty stays.
     *
     * @param  array<int|string, mixed>  $triggers
     * @param  Closure(array<string, mixed>): bool  $drop
     * @return list<mixed>
     */
    public static function reject(array $triggers, Closure $drop): array
    {
        $kept = [];

        foreach ($triggers as $entry) {
            if (! is_array($entry) || ! self::isGroup($entry)) {
                if (! is_array($entry) || ! $drop($entry)) {
                    $kept[] = $entry;
                }

                continue;
            }

            $conditions = self::conditionsOf($entry);
            $remaining = self::reject($conditions, $drop);

            if ($conditions === [] || $remaining !== []) {
                $kept[] = [...$entry, 'conditions' => $remaining];
            }
        }

        return $kept;
    }
}
