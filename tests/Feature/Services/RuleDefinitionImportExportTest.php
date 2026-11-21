<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Exceptions\RuleImportException;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\RuleDefinitionExporter;
use App\Services\RuleDefinitionImporter;
use App\Services\RuleEmailBaseline;
use App\Support\Rules\RuleTriggers;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->exporter = app(RuleDefinitionExporter::class);
    $this->importer = app(RuleDefinitionImporter::class);
});

function ruleFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/Rules/{$name}.json"));
}

function defRule(User $user, UserRuleGroup $group, string $name, int $order, array $overrides = []): UserRule
{
    return UserRule::factory()->for($user)->for($group, 'group')->create(array_merge([
        'name' => $name,
        'order' => $order,
        'triggers' => [['field' => 'description', 'operator' => 'contains', 'value' => mb_strtoupper($name)]],
        'actions' => [['type' => RuleActionType::SetNotes->value, 'value' => $name]],
    ], $overrides));
}

function defOwned(User $user, array $extraFirst = []): array
{
    foreach ($extraFirst as $name) {
        Tag::factory()->for($user)->create(['name' => $name]);
    }

    return [
        'category' => Category::factory()->for($user)->create(['name' => 'Groceries', 'is_hidden' => false]),
        'account' => Account::factory()->for($user)->create(['name' => 'Everyday']),
        'tag' => Tag::factory()->for($user)->create(['name' => 'Holiday']),
        'planned' => PlannedTransaction::factory()->for($user)->create(['description' => 'Rent']),
    ];
}

it('replaces the conditions of a same-named rule on re-import', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create(['name' => 'Spending', 'order' => 0]);
    Category::factory()->for($this->user)->create(['name' => 'Groceries', 'is_hidden' => false]);
    $groceries = defRule($this->user, $group, 'Groceries', 0);
    foreach (['Fuel', 'Rent', 'Coffee', 'Gym'] as $order => $name) {
        defRule($this->user, $group, $name, $order + 1);
    }

    $result = $this->importer->import($this->user, ruleFixture('groceries-new-condition'));

    expect(UserRule::where('user_id', $this->user->id)->count())->toBe(5)
        ->and($result->rulesUpdated)->toBe(1)
        ->and($result->rulesCreated)->toBe(0)
        ->and($groceries->fresh()->triggers)->toBe([['field' => 'description', 'operator' => 'contains', 'value' => 'ALDI']])
        ->and($groceries->fresh()->order)->toBe(0);
});

it('skips and reports a rule whose category path does not resolve', function () {
    $categories = Category::count();

    $result = $this->importer->import($this->user, ruleFixture('unresolved-category'));

    expect(Category::count())->toBe($categories)
        ->and(UserRule::where('user_id', $this->user->id)->pluck('name')->all())->toBe(['Fine'])
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0])->toContain('Mystery')->toContain("category 'Nowhere / Nothing' was not found");
});

it('fails the import and creates nothing when a rule has no actions', function () {
    expect(fn () => $this->importer->import($this->user, ruleFixture('no-actions')))
        ->toThrow(RuleImportException::class);

    expect(UserRule::count())->toBe(0)
        ->and(UserRuleGroup::count())->toBe(0);
});

it('restores exported rules in order with stop processing', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create(['name' => 'Ordered', 'order' => 0, 'stop_processing' => true]);
    foreach (['Third', 'First', 'Second'] as $i => $name) {
        defRule($this->user, $group, $name, [2, 0, 1][$i]);
    }
    $json = $this->exporter->toJson($this->user);
    $other = User::factory()->create();

    $this->importer->import($other, $json);

    $imported = UserRuleGroup::where('user_id', $other->id)->sole();

    expect($imported->stop_processing)->toBeTrue()
        ->and($imported->rules()->pluck('name')->all())->toBe(['First', 'Second', 'Third']);
});

it('resolves account triggers only against the importing user accounts', function () {
    $mine = Account::factory()->for($this->user)->create(['name' => 'Everyday']);
    $theirs = Account::factory()->for(User::factory()->create())->create(['name' => 'Everyday']);

    $this->importer->import($this->user, ruleFixture('account-trigger'));

    $trigger = UserRule::where('user_id', $this->user->id)->sole()->triggers[0];

    expect($trigger['value'])->toBe((string) $mine->id)->not->toBe((string) $theirs->id);
});

it('skips a rule naming an account the importing user does not have', function () {
    Account::factory()->for(User::factory()->create())->create(['name' => 'Everyday']);

    $result = $this->importer->import($this->user, ruleFixture('account-trigger'));

    expect(UserRule::count())->toBe(0)->and($result->skipped)->toHaveCount(1);
});

it('skips a rule when a name matches more than one of the users records', function () {
    Account::factory()->for($this->user)->count(2)->create(['name' => 'Everyday']);

    $result = $this->importer->import($this->user, ruleFixture('account-trigger'));

    expect(UserRule::count())->toBe(0)->and($result->skipped[0])->toContain('more than one');
});

it('round-trips every trigger field, operator and action type between users', function () {
    config(['services.typesafe.api_key' => 'ts_test', 'budget.rule_ai_categorisation' => true]);
    $source = defOwned($this->user);
    $target = User::factory()->create();
    defOwned($target, ['Aaa', 'Bbb']);
    Category::factory()->for($target)->create(['name' => 'Zed', 'is_hidden' => false]);

    $referenceValue = fn (RuleTriggerField $field): string => match ($field) {
        RuleTriggerField::CategoryId => (string) $source['category']->id,
        RuleTriggerField::AccountId => (string) $source['account']->id,
        RuleTriggerField::Tag => (string) $source['tag']->id,
        RuleTriggerField::Amount => '1000',
        RuleTriggerField::Direction => 'debit',
        default => 'x',
    };

    $fieldLeaves = array_map(static fn (RuleTriggerField $field): array => ['field' => $field->value, 'operator' => RuleTriggerOperator::Equals->value, 'value' => $referenceValue($field)], RuleTriggerField::cases());
    $operatorLeaves = array_map(static fn (RuleTriggerOperator $operator): array => [
        'field' => RuleTriggerField::Description->value,
        'operator' => $operator->value,
        'value' => match (true) {
            ! $operator->requiresValue() => '',
            $operator === RuleTriggerOperator::MatchesRegex => '^a+$',
            default => '5',
        },
    ], RuleTriggerOperator::cases());
    $tagOperatorLeaves = array_map(static fn (RuleTriggerOperator $operator): array => ['field' => RuleTriggerField::Tag->value, 'operator' => $operator->value, 'value' => $operator->requiresValue() ? (string) $source['tag']->id : ''], [RuleTriggerOperator::NotEquals, RuleTriggerOperator::IsEmpty, RuleTriggerOperator::IsNotEmpty]);

    $group = UserRuleGroup::factory()->for($this->user)->create(['name' => 'Everything', 'order' => 0, 'is_active' => false, 'stop_processing' => true, 'description' => 'All of it']);
    defRule($this->user, $group, 'All fields', 0, ['triggers' => $fieldLeaves, 'effective_from' => '2026-07-01', 'strict_mode' => false, 'is_auto_apply' => true, 'is_active' => false]);
    defRule($this->user, $group, 'All operators', 1, ['triggers' => [...$operatorLeaves, ...$tagOperatorLeaves]]);
    defRule($this->user, $group, 'Condition group', 2, ['triggers' => [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'A'],
        ['type' => RuleTriggers::GROUP, 'match' => RuleTriggers::MATCH_ANY, 'conditions' => [
            ['field' => 'category_id', 'operator' => 'equals', 'value' => (string) $source['category']->id],
            ['field' => 'tag', 'operator' => 'equals', 'value' => (string) $source['tag']->id],
        ]],
    ]]);

    $order = 3;
    foreach (RuleActionType::cases() as $type) {
        $action = match ($type) {
            RuleActionType::SetCategory => ['type' => $type->value, 'value' => (string) $source['category']->id],
            RuleActionType::MarkAsTransfer => ['type' => $type->value, 'value' => (string) $source['account']->id],
            RuleActionType::LinkToPlannedTransaction => ['type' => $type->value, 'value' => (string) $source['planned']->id],
            RuleActionType::AddTags => ['type' => $type->value, 'value' => '', 'tag_ids' => [$source['tag']->id]],
            RuleActionType::FoldIntoParent, RuleActionType::ExcludeFromReports, RuleActionType::EmailDigest, RuleActionType::SuggestCategoryWithAi => ['type' => $type->value, 'value' => ''],
            RuleActionType::SetDescription, RuleActionType::SetCleanDescription, RuleActionType::AppendNotes, RuleActionType::SetNotes => ['type' => $type->value, 'value' => 'text'],
        };
        defRule($this->user, $group, 'Action '.$type->value, $order++, ['actions' => [$action]]);
    }

    $exported = $this->exporter->export($this->user);
    $result = $this->importer->import($target, json_encode($exported, JSON_THROW_ON_ERROR));

    expect($result->skipped)->toBe([])
        ->and($result->rulesCreated)->toBe($order)
        ->and($this->exporter->export($target))->toBe($exported)
        ->and(UserRule::where('user_id', $target->id)->where('name', 'All fields')->sole()->effective_from->format('Y-m-d'))->toBe('2026-07-01');
});

it('exports names instead of ids', function () {
    $own = defOwned($this->user);
    $group = UserRuleGroup::factory()->for($this->user)->create(['name' => 'G']);
    defRule($this->user, $group, 'R', 0, [
        'triggers' => [['field' => 'tag', 'operator' => 'equals', 'value' => (string) $own['tag']->id]],
        'actions' => [['type' => 'add_tags', 'value' => '', 'tag_ids' => [$own['tag']->id]]],
    ]);

    $json = $this->exporter->toJson($this->user);

    expect($json)->toContain('"Holiday"')->not->toContain('tag_ids')->not->toContain("\"{$own['tag']->id}\"");
});

it('exports a reference to a deleted record as null so import reports it', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create(['name' => 'G']);
    defRule($this->user, $group, 'R', 0, ['actions' => [['type' => 'set_category', 'value' => '999999']]]);
    $json = $this->exporter->toJson($this->user);

    $result = $this->importer->import(User::factory()->create(), $json);

    expect(json_decode($json, true)['groups'][0]['rules'][0]['actions'][0]['value'])->toBeNull()
        ->and($result->skipped)->toHaveCount(1);
});

it('skips a rule whose add-tags action names a missing tag instead of adding fewer tags', function () {
    $tag = Tag::factory()->for($this->user)->create(['name' => 'Holiday']);
    $json = json_encode(['format' => 'can-eye-budget.rules', 'version' => 1, 'groups' => [['name' => 'G', 'rules' => [[
        'name' => 'Tags',
        'triggers' => [['field' => 'description', 'operator' => 'contains', 'value' => 'a']],
        'actions' => [['type' => 'add_tags', 'tag_names' => ['Holiday', 'Missing']]],
    ]]]]], JSON_THROW_ON_ERROR);

    $result = $this->importer->import($this->user, $json);

    expect(UserRule::count())->toBe(0)->and($result->skipped[0])->toContain("tag 'Missing' was not found");
});

it('validates imported rules with the rule form validation', function (array $rule, string $message) {
    $json = json_encode(['format' => 'can-eye-budget.rules', 'version' => 1, 'groups' => [['name' => 'G', 'rules' => [array_merge([
        'name' => 'Bad',
        'triggers' => [['field' => 'description', 'operator' => 'contains', 'value' => 'a']],
        'actions' => [['type' => 'set_notes', 'value' => 'n']],
    ], $rule)]]]], JSON_THROW_ON_ERROR);

    try {
        $this->importer->import($this->user, $json);
        $this->fail('The import should have failed.');
    } catch (RuleImportException $exception) {
        expect(implode(' ', $exception->errors))->toContain($message)
            ->and(UserRule::count())->toBe(0)
            ->and(UserRuleGroup::count())->toBe(0);
    }
})->with([
    'unknown field' => [['triggers' => [['field' => 'nope', 'operator' => 'contains', 'value' => 'a']]], 'selected triggers.0.field is invalid'],
    'unknown operator' => [['triggers' => [['field' => 'description', 'operator' => 'nope', 'value' => 'a']]], 'selected triggers.0.operator is invalid'],
    'unsafe regex' => [['triggers' => [['field' => 'description', 'operator' => 'matches_regex', 'value' => '(a)\1']]], 'cannot use back-references'],
    'invalid regex' => [['triggers' => [['field' => 'description', 'operator' => 'matches_regex', 'value' => '(']]], 'not a valid regular expression'],
    'duplicate action types' => [['actions' => [['type' => 'set_notes', 'value' => 'a'], ['type' => 'set_notes', 'value' => 'b']]], 'two actions of the same type'],
    'unknown action' => [['actions' => [['type' => 'nope', 'value' => 'a']]], 'selected actions.0.type is invalid'],
    'no triggers' => [['triggers' => []], 'triggers'],
    'nested groups' => [['triggers' => [['type' => 'group', 'match' => 'all', 'conditions' => [['type' => 'group', 'match' => 'all', 'conditions' => []]]]]], 'cannot be nested'],
    'bad effective date' => [['effective_from' => '01/07/2026'], 'effective_from'],
]);

it('rejects a file with null bytes', function () {
    $json = json_encode(['format' => 'can-eye-budget.rules', 'version' => 1, 'groups' => [['name' => 'G', 'rules' => [[
        'name' => 'R',
        'triggers' => [['field' => 'description', 'operator' => 'contains', 'value' => "a\0b"]],
        'actions' => [['type' => 'set_notes', 'value' => 'n']],
    ]]]]], JSON_THROW_ON_ERROR);

    expect(fn () => $this->importer->import($this->user, $json))->toThrow(RuleImportException::class, 'null bytes')
        ->and(UserRule::count())->toBe(0);
});

it('rejects files that are not rule definitions', function (string $json, string $message) {
    expect(fn () => $this->importer->import($this->user, $json))->toThrow(RuleImportException::class, $message);
})->with([
    'not json' => ['not json', 'not valid JSON'],
    'other format' => ['{"format":"x","version":1,"groups":[]}', 'format'],
    'other version' => ['{"format":"can-eye-budget.rules","version":2,"groups":[]}', 'version'],
    'a scalar' => ['5', 'not a rule definition file'],
]);

it('limits the size of an import', function () {
    expect(fn () => $this->importer->import($this->user, str_repeat(' ', RuleDefinitionImporter::MAX_BYTES + 1)))
        ->toThrow(RuleImportException::class, 'larger than');

    $groups = array_fill(0, RuleDefinitionImporter::MAX_GROUPS + 1, ['name' => 'G']);

    expect(fn () => $this->importer->import($this->user, json_encode(['format' => 'can-eye-budget.rules', 'version' => 1, 'groups' => $groups])))
        ->toThrow(RuleImportException::class);

    $rule = ['name' => 'R', 'triggers' => [['field' => 'description', 'operator' => 'contains', 'value' => 'a']], 'actions' => [['type' => 'set_notes', 'value' => 'n']]];
    $rules = array_fill(0, RuleDefinitionImporter::MAX_RULES + 1, $rule);

    expect(fn () => $this->importer->import($this->user, json_encode(['format' => 'can-eye-budget.rules', 'version' => 1, 'groups' => [['name' => 'G', 'rules' => $rules]]])))
        ->toThrow(RuleImportException::class, 'more than');
    expect(UserRule::count())->toBe(0);
});

it('appends new rules after existing ones in file order and creates unnamed rules', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create(['name' => 'Spending', 'order' => 0]);
    defRule($this->user, $group, 'Existing', 4);
    $rule = fn (string $name) => ['name' => $name, 'triggers' => [['field' => 'description', 'operator' => 'contains', 'value' => 'a']], 'actions' => [['type' => 'set_notes', 'value' => 'n']]];
    $json = json_encode(['format' => 'can-eye-budget.rules', 'version' => 1, 'groups' => [['name' => 'Spending', 'rules' => [$rule('B'), $rule(''), $rule('A')]]]]);

    $result = $this->importer->import($this->user, $json);

    expect(UserRuleGroup::where('user_id', $this->user->id)->count())->toBe(1)
        ->and($result->groupsCreated)->toBe(0)
        ->and($group->rules()->pluck('order', 'name')->all())->toBe(['Existing' => 4, 'B' => 5, 'Unnamed rule' => 6, 'A' => 7]);
});

it('only touches the importing user and never reads other users records', function () {
    $other = User::factory()->create();
    $otherGroup = UserRuleGroup::factory()->for($other)->create(['name' => 'Spending']);
    $otherRule = defRule($other, $otherGroup, 'Groceries', 0);
    Category::factory()->for($other)->create(['name' => 'Groceries', 'is_hidden' => false]);

    $result = $this->importer->import($this->user, ruleFixture('groceries-new-condition'));

    expect($otherRule->fresh()->triggers)->toBe([['field' => 'description', 'operator' => 'contains', 'value' => 'GROCERIES']])
        ->and(UserRule::where('user_id', $this->user->id)->count())->toBe(0)
        ->and($result->skipped)->toHaveCount(1);
});

it('records the email baseline for an imported active email rule and rolls everything back when it times out', function () {
    $json = json_encode(['format' => 'can-eye-budget.rules', 'version' => 1, 'groups' => [['name' => 'G', 'rules' => [[
        'name' => 'Mail',
        'triggers' => [['field' => 'description', 'operator' => 'contains', 'value' => 'a']],
        'actions' => [['type' => 'email_digest', 'value' => '']],
    ]]]]]);
    $account = Account::factory()->for($this->user)->create();
    App\Models\Transaction::factory()->create(['user_id' => $this->user->id, 'account_id' => $account->id, 'description' => 'abc']);
    app()->instance(RuleEmailBaseline::class, new RuleEmailBaseline(app(App\Services\RuleEvaluator::class), -1));

    expect(fn () => $this->importer->import($this->user, $json))->toThrow(RuleImportException::class, 'took too long')
        ->and(UserRule::count())->toBe(0)
        ->and(UserRuleGroup::count())->toBe(0);
});

it('records what an imported email rule already matches so its backlog is not emailed', function () {
    $account = Account::factory()->for($this->user)->create();
    App\Models\Transaction::factory()->count(2)->create(['user_id' => $this->user->id, 'account_id' => $account->id, 'description' => 'abc']);
    $json = json_encode(['format' => 'can-eye-budget.rules', 'version' => 1, 'groups' => [['name' => 'G', 'rules' => [[
        'name' => 'Mail',
        'triggers' => [['field' => 'description', 'operator' => 'contains', 'value' => 'a']],
        'actions' => [['type' => 'email_digest', 'value' => '']],
    ]]]]]);

    $this->importer->import($this->user, $json);

    expect(App\Models\RuleEmailDelivery::where('user_rule_id', UserRule::sole()->id)->count())->toBe(2);
});

it('leaves the settings of an existing same-named group alone and only applies them to new groups', function () {
    $existing = UserRuleGroup::factory()->for($this->user)->create(['name' => 'Spending', 'description' => 'Mine', 'is_active' => false, 'stop_processing' => true, 'order' => 3]);
    $rule = ['name' => 'R', 'triggers' => [['field' => 'description', 'operator' => 'contains', 'value' => 'a']], 'actions' => [['type' => 'set_notes', 'value' => 'n']]];
    $json = json_encode(['format' => 'can-eye-budget.rules', 'version' => 1, 'groups' => [
        ['name' => 'Spending', 'description' => 'Theirs', 'is_active' => true, 'stop_processing' => false, 'rules' => [$rule]],
        ['name' => 'Fresh', 'description' => 'New', 'is_active' => false, 'stop_processing' => true, 'rules' => [$rule]],
    ]]);

    $this->importer->import($this->user, $json);

    $fresh = UserRuleGroup::where('user_id', $this->user->id)->where('name', 'Fresh')->sole();

    expect($existing->fresh()->only('description', 'is_active', 'stop_processing', 'order'))->toBe(['description' => 'Mine', 'is_active' => false, 'stop_processing' => true, 'order' => 3])
        ->and($existing->rules()->count())->toBe(1)
        ->and($fresh->only('description', 'is_active', 'stop_processing'))->toBe(['description' => 'New', 'is_active' => false, 'stop_processing' => true]);
});

it('counts the size limit in bytes, not characters', function () {
    expect(fn () => $this->importer->import($this->user, str_repeat('é', (int) (RuleDefinitionImporter::MAX_BYTES / 2) + 1)))
        ->toThrow(RuleImportException::class, 'larger than');
});
