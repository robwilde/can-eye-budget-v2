<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Tag;
use App\Support\Rules\RuleTriggers;

/**
 * Turns the ids a rule stores (categories, accounts, tags, planned transactions) into names that
 * mean the same thing in any user's data, and back again. Names are only ever looked up among the
 * given user's own records. A name that matches nothing, or more than one record, does not
 * resolve; nothing is guessed or created.
 */
final class RuleDefinitionReferences
{
    private const string CATEGORY = 'category';

    private const string ACCOUNT = 'account';

    private const string TAG = 'tag';

    private const string PLANNED = 'planned transaction';

    /** @var array<string, array<string, string>> */
    private array $namesById = [];

    /** @var array<string, array<string, list<string>>> */
    private array $idsByName = [];

    public function __construct(private readonly int $userId) {}

    /**
     * @param  array<int|string, mixed>  $triggers
     * @return list<mixed>
     */
    public function portableTriggers(array $triggers): array
    {
        return RuleTriggers::map($triggers, function (array $condition): array {
            $kind = $this->triggerKind($condition['field'] ?? null);

            if ($kind === null || ! $this->hasValue($condition['value'] ?? null)) {
                return $condition;
            }

            return [...$condition, 'value' => $this->nameOf($kind, (string) $condition['value'])];
        });
    }

    /**
     * @param  array<int|string, mixed>  $actions
     * @return list<array<string, mixed>>
     */
    public function portableActions(array $actions): array
    {
        $portable = [];

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            $type = RuleActionType::tryFrom((string) ($action['type'] ?? ''));

            if ($type === RuleActionType::AddTags) {
                $ids = is_array($action['tag_ids'] ?? null) ? $action['tag_ids'] : [];
                $portable[] = ['type' => $type->value, 'tag_names' => array_map(fn (mixed $id): ?string => $this->nameOf(self::TAG, (string) $id), array_values($ids))];

                continue;
            }

            $kind = $this->actionKind($type);
            $value = $action['value'] ?? '';
            $portable[] = ['type' => (string) ($action['type'] ?? ''), 'value' => $kind !== null && $this->hasValue($value) ? $this->nameOf($kind, (string) $value) : $value];
        }

        return $portable;
    }

    /**
     * @param  array<int|string, mixed>  $triggers
     * @param  list<string>  $unresolved  Receives one line per name that did not resolve
     * @return list<mixed>
     */
    public function resolveTriggers(array $triggers, array &$unresolved): array
    {
        return RuleTriggers::map($triggers, function (array $condition) use (&$unresolved): array {
            $kind = $this->triggerKind($condition['field'] ?? null);
            $operator = RuleTriggerOperator::tryFrom((string) ($condition['operator'] ?? ''));

            if ($kind === null || $operator?->requiresValue() === false) {
                return $condition;
            }

            return [...$condition, 'value' => $this->idOf($kind, $condition['value'] ?? null, $unresolved)];
        });
    }

    /**
     * @param  array<int|string, mixed>  $actions
     * @param  list<string>  $unresolved  Receives one line per name that did not resolve
     * @return list<array<string, mixed>>
     */
    public function resolveActions(array $actions, array &$unresolved): array
    {
        $resolved = [];

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            $type = RuleActionType::tryFrom((string) ($action['type'] ?? ''));

            if ($type === RuleActionType::AddTags) {
                $names = is_array($action['tag_names'] ?? null) ? array_values($action['tag_names']) : [];
                $ids = [];

                foreach ($names as $name) {
                    $id = $this->idOf(self::TAG, $name, $unresolved);

                    if ($id !== null) {
                        $ids[] = (int) $id;
                    }
                }

                $resolved[] = ['type' => $type->value, 'value' => '', 'tag_ids' => array_values(array_unique($ids))];

                continue;
            }

            $kind = $this->actionKind($type);
            $value = $action['value'] ?? '';
            $resolved[] = ['type' => $action['type'] ?? '', 'value' => $kind !== null ? $this->idOf($kind, $value, $unresolved) : $value];
        }

        return $resolved;
    }

    private function hasValue(mixed $value): bool
    {
        return $value !== null && $value !== '' && (is_string($value) || is_int($value));
    }

    private function triggerKind(mixed $field): ?string
    {
        return match ($field) {
            RuleTriggerField::CategoryId->value => self::CATEGORY,
            RuleTriggerField::AccountId->value => self::ACCOUNT,
            RuleTriggerField::Tag->value => self::TAG,
            default => null,
        };
    }

    private function actionKind(?RuleActionType $type): ?string
    {
        return match ($type) {
            RuleActionType::SetCategory => self::CATEGORY,
            RuleActionType::MarkAsTransfer => self::ACCOUNT,
            RuleActionType::LinkToPlannedTransaction => self::PLANNED,
            default => null,
        };
    }

    private function nameOf(string $kind, string $id): ?string
    {
        return $this->names($kind)[$id] ?? null;
    }

    /**
     * @param  list<string>  $unresolved
     */
    private function idOf(string $kind, mixed $name, array &$unresolved): ?string
    {
        if (! is_string($name) || mb_trim($name) === '') {
            $unresolved[] = "a {$kind} that was not named in the file";

            return null;
        }

        $ids = $this->idsByName($kind)[mb_strtolower(mb_trim($name))] ?? [];

        if (count($ids) === 1) {
            return $ids[0];
        }

        $unresolved[] = $ids === [] ? "{$kind} '{$name}' was not found" : "{$kind} '{$name}' matches more than one of your records";

        return null;
    }

    /** @return array<string, string> */
    private function names(string $kind): array
    {
        if (! isset($this->namesById[$kind])) {
            $this->namesById[$kind] = match ($kind) {
                self::CATEGORY => Category::allWithLinkedParents($this->userId)
                    ->mapWithKeys(fn (Category $category): array => [(string) $category->id => $category->fullPath()])->all(),
                self::ACCOUNT => Account::where('user_id', $this->userId)->pluck('name', 'id')->mapWithKeys(fn (string $name, int|string $id): array => [(string) $id => $name])->all(),
                self::TAG => Tag::where('user_id', $this->userId)->pluck('name', 'id')->mapWithKeys(fn (string $name, int|string $id): array => [(string) $id => $name])->all(),
                default => PlannedTransaction::where('user_id', $this->userId)->pluck('description', 'id')->mapWithKeys(fn (string $name, int|string $id): array => [(string) $id => $name])->all(),
            };
        }

        return $this->namesById[$kind];
    }

    /** @return array<string, list<string>> */
    private function idsByName(string $kind): array
    {
        if (! isset($this->idsByName[$kind])) {
            $index = [];

            foreach ($this->names($kind) as $id => $name) {
                $index[mb_strtolower(mb_trim($name))][] = (string) $id;
            }

            $this->idsByName[$kind] = $index;
        }

        return $this->idsByName[$kind];
    }
}
