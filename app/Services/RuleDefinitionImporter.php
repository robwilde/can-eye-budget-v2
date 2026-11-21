<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\RuleImportResult;
use App\Enums\RuleActionType;
use App\Exceptions\RuleBaselineTimedOutException;
use App\Exceptions\RuleImportException;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Support\Rules\RuleAiCategorisation;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use JsonException;

/**
 * Reads a rule definition file written by RuleDefinitionExporter into one user's rules.
 *
 * Everything runs in one transaction, so a file that fails leaves no rule behind. A rule is
 * validated by the same RuleFormRules the rule form uses. A rule that names a category, account,
 * tag or planned transaction the user does not have is skipped and reported, and nothing is
 * created to make it fit. A named rule replaces the rule of that name in the group of that name;
 * any other rule is appended after the group's existing ones, in file order. A group that already
 * exists by name keeps its own active, stop-processing and description settings; only a group the
 * import creates takes them from the file.
 */
final class RuleDefinitionImporter
{
    public const int MAX_BYTES = 1_048_576;

    public const int MAX_GROUPS = 100;

    public const int MAX_RULES = 1000;

    private const string UNNAMED_RULE = 'Unnamed rule';

    public function __construct(private readonly RuleFormRules $formRules) {}

    /**
     * @throws RuleImportException
     */
    public function import(User $user, string $json): RuleImportResult
    {
        $document = $this->decode($json);

        try {
            return DB::transaction(fn (): RuleImportResult => $this->write($user, $document, $this->plan($user, $document)));
        } catch (RuleBaselineTimedOutException) {
            throw new RuleImportException(['Recording the transactions an email rule already matches took too long. Nothing was imported; try again in a moment.']);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RuleImportException
     */
    private function decode(string $json): array
    {
        if (mb_strlen($json, '8bit') > self::MAX_BYTES) {
            throw new RuleImportException(['The file is larger than '.(self::MAX_BYTES / 1_048_576).' MB.']);
        }

        try {
            $document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuleImportException(['The file is not valid JSON.']);
        }

        if (! is_array($document)) {
            throw new RuleImportException(['The file is not a rule definition file.']);
        }

        if ($this->containsNullByte($document)) {
            throw new RuleImportException(['The file contains null bytes.']);
        }

        $validator = Validator::make($document, [
            'format' => ['required', 'in:'.RuleDefinitionExporter::FORMAT],
            'version' => ['required', 'in:'.RuleDefinitionExporter::VERSION],
            'groups' => ['required', 'array', 'max:'.self::MAX_GROUPS],
            'groups.*' => ['array'],
            'groups.*.name' => ['required', 'string', 'max:255'],
            'groups.*.description' => ['nullable', 'string', 'max:255'],
            'groups.*.is_active' => ['sometimes', 'boolean'],
            'groups.*.stop_processing' => ['sometimes', 'boolean'],
            'groups.*.rules' => ['sometimes', 'array'],
            'groups.*.rules.*' => ['array'],
            'groups.*.rules.*.name' => ['nullable', 'string', 'max:255'],
            'groups.*.rules.*.description' => ['nullable', 'string', 'max:255'],
            'groups.*.rules.*.is_active' => ['sometimes', 'boolean'],
            'groups.*.rules.*.is_auto_apply' => ['sometimes', 'boolean'],
            'groups.*.rules.*.strict_mode' => ['sometimes', 'boolean'],
            'groups.*.rules.*.effective_from' => ['nullable', 'date_format:Y-m-d'],
            'groups.*.rules.*.triggers' => ['present', 'array'],
            'groups.*.rules.*.triggers.*' => ['array'],
            'groups.*.rules.*.actions' => ['present', 'array'],
            'groups.*.rules.*.actions.*' => ['array'],
        ]);

        if ($validator->fails()) {
            throw new RuleImportException(array_values(array_unique($validator->errors()->all())));
        }

        $ruleCount = array_sum(array_map(static fn (array $group): int => count($group['rules'] ?? []), $document['groups']));

        if ($ruleCount > self::MAX_RULES) {
            throw new RuleImportException(['The file has more than '.self::MAX_RULES.' rules.']);
        }

        return $document;
    }

    /**
     * Resolves and validates every rule before anything is written, so a bad rule anywhere in
     * the file fails the whole import.
     *
     * @param  array<string, mixed>  $document
     * @return array<int, array<int, array{attributes: array<string, mixed>}|array{skipped: list<string>}>>
     *
     * @throws RuleImportException
     */
    private function plan(User $user, array $document): array
    {
        $references = new RuleDefinitionReferences($user->id);
        $plan = [];
        $errors = [];

        foreach ($document['groups'] as $groupIndex => $group) {
            foreach ($group['rules'] ?? [] as $ruleIndex => $rule) {
                $label = sprintf("Group '%s', rule '%s'", $group['name'], $rule['name'] ?? '');
                $unresolved = [];
                $triggers = $references->resolveTriggers($rule['triggers'], $unresolved);
                $actions = $references->resolveActions($rule['actions'], $unresolved);

                $validator = Validator::make(
                    ['triggers' => $triggers, 'actions' => $actions],
                    $this->formRules->forDraft($triggers, $actions, $user->id, $this->aiActionAllowed()),
                    $this->formRules->messages(),
                );

                if ($unresolved !== [] && $this->onlyReferenceFailures($validator->errors()->keys(), $triggers, $actions)) {
                    $plan[$groupIndex][$ruleIndex] = ['skipped' => array_values(array_unique($unresolved))];

                    continue;
                }

                if ($validator->fails()) {
                    foreach (array_unique($validator->errors()->all()) as $message) {
                        $errors[] = "{$label}: {$message}";
                    }

                    continue;
                }

                $plan[$groupIndex][$ruleIndex] = ['attributes' => [
                    'description' => $this->blankToNull($rule['description'] ?? null),
                    'triggers' => $triggers,
                    'actions' => $actions,
                    'strict_mode' => (bool) ($rule['strict_mode'] ?? true),
                    'is_auto_apply' => (bool) ($rule['is_auto_apply'] ?? false),
                    'is_active' => (bool) ($rule['is_active'] ?? true),
                    'effective_from' => $this->blankToNull($rule['effective_from'] ?? null),
                ]];
            }
        }

        if ($errors !== []) {
            throw new RuleImportException($errors);
        }

        return $plan;
    }

    /**
     * Whether every failure is a reference that did not resolve (a null where an id belongs),
     * as opposed to a rule that is wrong in itself.
     *
     * @param  list<string>  $failedKeys
     * @param  list<mixed>  $triggers
     * @param  list<array<string, mixed>>  $actions
     */
    private function onlyReferenceFailures(array $failedKeys, array $triggers, array $actions): bool
    {
        foreach ($failedKeys as $key) {
            if (! $this->isUnresolvedSlot($key, $triggers, $actions)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<mixed>  $triggers
     * @param  list<array<string, mixed>>  $actions
     */
    private function isUnresolvedSlot(string $key, array $triggers, array $actions): bool
    {
        $value = data_get(['triggers' => $triggers, 'actions' => $actions], $key);

        return $value === null || (is_array($value) && str_contains($key, 'tag_ids') && count($value) < 1);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<int, array<int, array<string, mixed>>>  $plan
     */
    private function write(User $user, array $document, array $plan): RuleImportResult
    {
        $groupsCreated = 0;
        $rulesCreated = 0;
        $rulesUpdated = 0;
        $skipped = [];
        $nextGroupOrder = (int) (UserRuleGroup::where('user_id', $user->id)->max('order') ?? -1) + 1;

        foreach ($document['groups'] as $groupIndex => $definition) {
            $group = null;
            $ensureGroup = function () use (&$group, &$groupsCreated, &$nextGroupOrder, $user, $definition): UserRuleGroup {
                if ($group instanceof UserRuleGroup) {
                    return $group;
                }

                $group = UserRuleGroup::where('user_id', $user->id)->where('name', $definition['name'])->orderBy('id')->first();

                if (! $group instanceof UserRuleGroup) {
                    $group = UserRuleGroup::create([
                        'user_id' => $user->id,
                        'name' => $definition['name'],
                        'description' => $this->blankToNull($definition['description'] ?? null),
                        'is_active' => (bool) ($definition['is_active'] ?? true),
                        'stop_processing' => (bool) ($definition['stop_processing'] ?? false),
                        'order' => $nextGroupOrder++,
                    ]);
                    $groupsCreated++;
                }

                return $group;
            };

            $rules = $definition['rules'] ?? [];

            if ($rules === []) {
                $ensureGroup();
            }

            foreach ($rules as $ruleIndex => $rule) {
                $entry = $plan[$groupIndex][$ruleIndex];

                if (isset($entry['skipped'])) {
                    $skipped[] = sprintf("Group '%s', rule '%s': %s", $definition['name'], $rule['name'] ?? '', implode('; ', $entry['skipped']));

                    continue;
                }

                $target = $ensureGroup();
                $name = mb_trim((string) ($rule['name'] ?? ''));
                $existing = $name === '' ? null : UserRule::where('user_id', $user->id)
                    ->where('user_rule_group_id', $target->id)
                    ->where('name', $name)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof UserRule) {
                    $wasListening = $existing->is_active && $this->hasEmailAction($existing->actions);
                    $existing->update($entry['attributes']);
                    $this->recordBaseline($existing, $wasListening);
                    $rulesUpdated++;

                    continue;
                }

                $created = UserRule::create([
                    ...$entry['attributes'],
                    'user_id' => $user->id,
                    'user_rule_group_id' => $target->id,
                    'name' => $name === '' ? self::UNNAMED_RULE : $name,
                    'order' => (int) (UserRule::where('user_rule_group_id', $target->id)->max('order') ?? -1) + 1,
                ]);
                $this->recordBaseline($created, false);
                $rulesCreated++;
            }
        }

        return new RuleImportResult($groupsCreated, $rulesCreated, $rulesUpdated, $skipped);
    }

    private function aiActionAllowed(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === RuleActionType::SuggestCategoryWithAi->value && ! RuleAiCategorisation::available()) {
                $fail('The AI categorise action is not available.');
            }
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     */
    private function hasEmailAction(array $actions): bool
    {
        return array_any($actions, static fn (array $action): bool => ($action['type'] ?? null) === RuleActionType::EmailDigest->value);
    }

    /**
     * An imported email rule that is switched on records what it already matches as delivered, in
     * the import's transaction, as saving the rule in the form does.
     *
     * @throws RuleBaselineTimedOutException
     */
    private function recordBaseline(UserRule $rule, bool $wasListening): void
    {
        if (! $wasListening && $rule->is_active && $this->hasEmailAction($rule->actions)) {
            app(RuleEmailBaseline::class)->record($rule);
        }
    }

    private function blankToNull(mixed $value): ?string
    {
        return is_string($value) && mb_trim($value) !== '' ? $value : null;
    }

    private function containsNullByte(mixed $value): bool
    {
        if (is_string($value)) {
            return str_contains($value, "\0");
        }

        return is_array($value) && (array_any(array_keys($value), fn (mixed $key): bool => $this->containsNullByte($key)) || array_any($value, fn (mixed $item): bool => $this->containsNullByte($item)));
    }
}
