<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\PipelineTrigger;
use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Jobs\RunTransactionAnalysisJob;
use App\Livewire\UserRuleManager;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
});

// ─── Rendering ────────────────────────────────────────────

test('component renders for authenticated user', function () {
    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->assertOk()
        ->assertSee('Rules');
});

test('shows empty state when no groups exist', function () {
    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->assertSee('No rule groups yet');
});

test('lists groups ordered by order field', function () {
    UserRuleGroup::factory()->for($this->user)->create(['name' => 'Second Group', 'order' => 1]);
    UserRuleGroup::factory()->for($this->user)->create(['name' => 'First Group', 'order' => 0]);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->assertSeeInOrder(['First Group', 'Second Group']);
});

// ─── Group CRUD ──────────────────────────────────────────

test('can create a new rule group', function () {
    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openAddGroupModal')
        ->set('groupName', 'My Test Group')
        ->set('groupDescription', 'A description')
        ->call('saveGroup')
        ->assertDontSee('No rule groups yet')
        ->assertSee('My Test Group');

    expect(UserRuleGroup::where('user_id', $this->user->id)->count())->toBe(1);
});

test('can edit an existing rule group', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create(['name' => 'Original Name']);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openEditGroupModal', $group->id)
        ->assertSet('groupName', 'Original Name')
        ->set('groupName', 'Updated Name')
        ->call('saveGroup')
        ->assertSee('Updated Name');

    expect($group->fresh()->name)->toBe('Updated Name');
});

test('can delete a rule group and its rules', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create(['name' => 'Doomed Group']);
    UserRule::factory()->for($this->user)->for($group, 'group')->create();

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('confirmDeleteGroup', $group->id)
        ->call('deleteGroup');

    expect(UserRuleGroup::find($group->id))->toBeNull()
        ->and(UserRule::where('user_rule_group_id', $group->id)->count())->toBe(0);
});

test('can toggle group active state', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create(['is_active' => true]);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('toggleGroupActive', $group->id);

    expect($group->fresh()->is_active)->toBeFalse();
});

test('can toggle group stop_processing', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create(['stop_processing' => false]);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('toggleGroupStopProcessing', $group->id);

    expect($group->fresh()->stop_processing)->toBeTrue();
});

test('cannot access another user groups', function () {
    $otherUser = User::factory()->create();
    UserRuleGroup::factory()->for($otherUser)->create(['name' => 'Secret Group']);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->assertDontSee('Secret Group');
});

// ─── Rule CRUD ───────────────────────────────────────────

test('can create a new rule with triggers and actions', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();
    $category = Category::factory()->create();

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openAddRuleModal', $group->id)
        ->set('ruleName', 'Netflix Rule')
        ->set('triggers.0.field', RuleTriggerField::Description->value)
        ->set('triggers.0.operator', RuleTriggerOperator::Contains->value)
        ->set('triggers.0.value', 'NETFLIX')
        ->set('actions.0.type', RuleActionType::SetCategory->value)
        ->set('actions.0.value', (string) $category->id)
        ->call('saveRule')
        ->assertSee('Netflix Rule');

    $rule = UserRule::where('user_id', $this->user->id)->first();

    expect($rule)->not->toBeNull()
        ->and($rule->name)->toBe('Netflix Rule')
        ->and($rule->triggers)->toHaveCount(1)
        ->and($rule->actions)->toHaveCount(1);
});

test('can edit an existing rule', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();
    $rule = UserRule::factory()->for($this->user)->for($group, 'group')->create(['name' => 'Old Rule']);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openEditRuleModal', $rule->id)
        ->assertSet('ruleName', 'Old Rule')
        ->set('ruleName', 'New Rule')
        ->call('saveRule')
        ->assertSee('New Rule');

    expect($rule->fresh()->name)->toBe('New Rule');
});

test('can delete a rule', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();
    $rule = UserRule::factory()->for($this->user)->for($group, 'group')->create(['name' => 'Bye Rule']);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('confirmDeleteRule', $rule->id)
        ->call('deleteRule');

    expect(UserRule::find($rule->id))->toBeNull();
});

test('validates required fields on rule save', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openAddRuleModal', $group->id)
        ->set('ruleName', '')
        ->set('triggers', [])
        ->set('actions', [])
        ->call('saveRule')
        ->assertHasErrors(['ruleName', 'triggers', 'actions']);
});

test('can add and remove trigger rows', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openAddRuleModal', $group->id)
        ->assertCount('triggers', 1)
        ->call('addTrigger')
        ->assertCount('triggers', 2)
        ->call('removeTrigger', 0)
        ->assertCount('triggers', 1);
});

test('can add and remove action rows', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openAddRuleModal', $group->id)
        ->assertCount('actions', 1)
        ->call('addAction')
        ->assertCount('actions', 2)
        ->call('removeAction', 0)
        ->assertCount('actions', 1);
});

test('can toggle rule active state', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();
    $rule = UserRule::factory()->for($this->user)->for($group, 'group')->create(['is_active' => true]);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('toggleRuleActive', $rule->id);

    expect($rule->fresh()->is_active)->toBeFalse();
});

test('can toggle rule auto_apply', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();
    $rule = UserRule::factory()->for($this->user)->for($group, 'group')->create(['is_auto_apply' => false]);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('toggleRuleAutoApply', $rule->id);

    expect($rule->fresh()->is_auto_apply)->toBeTrue();
});

test('the auto-apply switch shows the rule\'s current state', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();
    $auto = UserRule::factory()->for($this->user)->for($group, 'group')->autoApply()->create();
    $manual = UserRule::factory()->for($this->user)->for($group, 'group')->create();

    $html = Livewire::actingAs($this->user)->test(UserRuleManager::class)->html();

    $switchFor = function (UserRule $rule) use ($html): string {
        preg_match('/<ui-switch[^>]*toggleRuleAutoApply\('.$rule->id.'\)[^>]*>/', $html, $match);

        return $match[0] ?? '';
    };

    // Match the attribute, not the data-checked:… class variants in the class list.
    expect($switchFor($auto))->toMatch('/\sdata-checked[\s>]/')
        ->and($switchFor($manual))->not->toBe('')
        ->and($switchFor($manual))->not->toMatch('/\sdata-checked[\s>]/');
});

// ─── Run / Apply ──────────────────────────────────────────

test('run button is disabled when the only active rule sits in an inactive group', function () {
    $inactiveGroup = UserRuleGroup::factory()->for($this->user)->create(['is_active' => false]);
    UserRule::factory()->for($this->user)->for($inactiveGroup, 'group')->create();

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->assertViewHas('hasActiveRules', false);

    $activeGroup = UserRuleGroup::factory()->for($this->user)->create(['is_active' => true]);
    UserRule::factory()->for($this->user)->for($activeGroup, 'group')->create();

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->assertViewHas('hasActiveRules', true);
});

test('runRules queues the analysis job with the manual trigger', function () {
    Queue::fake();

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('runRules');

    Queue::assertPushed(
        RunTransactionAnalysisJob::class,
        fn (RunTransactionAnalysisJob $job): bool => $job->user->is($this->user)
            && $job->trigger === PipelineTrigger::Manual,
    );
});

test('applyRule categorises every matching transaction for a manual rule', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    $group = UserRuleGroup::factory()->for($this->user)->create();
    $rule = UserRule::factory()->for($this->user)->for($group, 'group')->create([
        'is_auto_apply' => false,
        'triggers' => [['field' => RuleTriggerField::Description->value, 'operator' => RuleTriggerOperator::Contains->value, 'value' => 'NETFLIX']],
        'actions' => [['type' => RuleActionType::SetCategory->value, 'value' => (string) $category->id]],
    ]);
    $account = Account::factory()->for($this->user)->create();
    $matches = Transaction::factory()->for($this->user)->for($account)->count(2)->create([
        'description' => 'NETFLIX.COM',
        'category_id' => null,
        'transfer_pair_id' => null,
    ]);
    $other = Transaction::factory()->for($this->user)->for($account)->create([
        'description' => 'SPOTIFY',
        'category_id' => null,
        'transfer_pair_id' => null,
    ]);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('applyRule', $rule->id);

    expect($matches->map->fresh()->pluck('category_id')->all())->toBe([$category->id, $category->id])
        ->and($other->fresh()->category_id)->toBeNull();
});

test('applyRule renames every matching transaction, replacing an existing clean description', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();
    $rule = UserRule::factory()->for($this->user)->for($group, 'group')->create([
        'triggers' => [['field' => RuleTriggerField::Description->value, 'operator' => RuleTriggerOperator::Contains->value, 'value' => 'ACME SOFTWARE']],
        'actions' => [['type' => RuleActionType::SetCleanDescription->value, 'value' => 'Acme hosting']],
    ]);
    $account = Account::factory()->for($this->user)->create();
    $unnamed = Transaction::factory()->for($this->user)->for($account)->fromRedbark()->create([
        'description' => 'ACME SOFTWARE PTY LTD 1833',
        'clean_description' => null,
    ]);
    $named = Transaction::factory()->for($this->user)->for($account)->fromRedbark()->create([
        'description' => 'ACME SOFTWARE PTY LTD 1902',
        'clean_description' => 'Acme domains',
    ]);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('applyRule', $rule->id);

    expect($unnamed->fresh()->clean_description)->toBe('Acme hosting')
        ->and($named->fresh()->clean_description)->toBe('Acme hosting')
        ->and($named->fresh()->description)->toBe('ACME SOFTWARE PTY LTD 1902');
});

test('applyRule ignores another user\'s rule and an inactive rule', function (bool $foreign) {
    $category = Category::factory()->create(['is_hidden' => false]);
    $owner = $foreign ? User::factory()->create() : $this->user;
    $group = UserRuleGroup::factory()->for($owner)->create();
    $rule = UserRule::factory()->for($owner)->for($group, 'group')->create([
        'is_active' => $foreign,
        'triggers' => [['field' => RuleTriggerField::Description->value, 'operator' => RuleTriggerOperator::Contains->value, 'value' => 'NETFLIX']],
        'actions' => [['type' => RuleActionType::SetCategory->value, 'value' => (string) $category->id]],
    ]);
    $txn = Transaction::factory()->for($owner)->for(Account::factory()->for($owner))->create([
        'description' => 'NETFLIX.COM',
        'category_id' => null,
        'transfer_pair_id' => null,
    ]);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('applyRule', $rule->id);

    expect($txn->fresh()->category_id)->toBeNull();
})->with(['another user\'s rule' => true, 'an inactive rule' => false]);

test('cannot save rule without at least one trigger and one action', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openAddRuleModal', $group->id)
        ->set('ruleName', 'Test Rule')
        ->set('triggers', [])
        ->set('actions', [])
        ->call('saveRule')
        ->assertHasErrors(['triggers', 'actions']);

    expect(UserRule::where('user_id', $this->user->id)->count())->toBe(0);
});

// ─── Reorder ─────────────────────────────────────────────

test('can move group up', function () {
    $groupA = UserRuleGroup::factory()->for($this->user)->create(['name' => 'Group A', 'order' => 0]);
    $groupB = UserRuleGroup::factory()->for($this->user)->create(['name' => 'Group B', 'order' => 1]);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('moveGroupUp', $groupB->id);

    expect($groupB->fresh()->order)->toBe(0)
        ->and($groupA->fresh()->order)->toBe(1);
});

test('can move group down', function () {
    $groupA = UserRuleGroup::factory()->for($this->user)->create(['name' => 'Group A', 'order' => 0]);
    $groupB = UserRuleGroup::factory()->for($this->user)->create(['name' => 'Group B', 'order' => 1]);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('moveGroupDown', $groupA->id);

    expect($groupA->fresh()->order)->toBe(1)
        ->and($groupB->fresh()->order)->toBe(0);
});

test('can move rule up within group', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();
    $ruleA = UserRule::factory()->for($this->user)->for($group, 'group')->create(['name' => 'Rule A', 'order' => 0]);
    $ruleB = UserRule::factory()->for($this->user)->for($group, 'group')->create(['name' => 'Rule B', 'order' => 1]);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('moveRuleUp', $ruleB->id);

    expect($ruleB->fresh()->order)->toBe(0)
        ->and($ruleA->fresh()->order)->toBe(1);
});

test('can move rule down within group', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();
    $ruleA = UserRule::factory()->for($this->user)->for($group, 'group')->create(['name' => 'Rule A', 'order' => 0]);
    $ruleB = UserRule::factory()->for($this->user)->for($group, 'group')->create(['name' => 'Rule B', 'order' => 1]);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('moveRuleDown', $ruleA->id);

    expect($ruleA->fresh()->order)->toBe(1)
        ->and($ruleB->fresh()->order)->toBe(0);
});

// ─── Action Value Validation ─────────────────────────────

test('can save a rule with a fold_into_parent action and empty value', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openAddRuleModal', $group->id)
        ->set('ruleName', 'Fold Fees')
        ->set('triggers.0.field', RuleTriggerField::Description->value)
        ->set('triggers.0.operator', RuleTriggerOperator::StartsWith->value)
        ->set('triggers.0.value', 'Int Tran Fee')
        ->set('actions.0.type', RuleActionType::FoldIntoParent->value)
        ->set('actions.0.value', '')
        ->call('saveRule')
        ->assertHasNoErrors();

    $rule = UserRule::where('user_id', $this->user->id)->first();

    expect($rule)->not->toBeNull()
        ->and($rule->actions[0]['type'])->toBe(RuleActionType::FoldIntoParent->value);
});

test('a value-requiring action with empty value fails validation', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openAddRuleModal', $group->id)
        ->set('ruleName', 'Bad Rule')
        ->set('triggers.0.field', RuleTriggerField::Description->value)
        ->set('triggers.0.operator', RuleTriggerOperator::Contains->value)
        ->set('triggers.0.value', 'NETFLIX')
        ->set('actions.0.type', RuleActionType::SetCategory->value)
        ->set('actions.0.value', '')
        ->call('saveRule')
        ->assertHasErrors(['actions.0.value']);

    expect(UserRule::where('user_id', $this->user->id)->count())->toBe(0);
});

test('a set_clean_description value longer than the column fails validation', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();

    $component = Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openAddRuleModal', $group->id)
        ->set('ruleName', 'Name Acme')
        ->set('triggers.0.field', RuleTriggerField::Description->value)
        ->set('triggers.0.operator', RuleTriggerOperator::Contains->value)
        ->set('triggers.0.value', 'ACME SOFTWARE')
        ->set('actions.0.type', RuleActionType::SetCleanDescription->value)
        ->set('actions.0.value', str_repeat('a', 256))
        ->call('saveRule')
        ->assertHasErrors(['actions.0.value' => 'max']);

    expect(UserRule::where('user_id', $this->user->id)->count())->toBe(0);

    $component->set('actions.0.value', str_repeat('a', 255))
        ->call('saveRule')
        ->assertHasNoErrors();

    expect(UserRule::where('user_id', $this->user->id)->sole()->actions[0]['value'])->toBe(str_repeat('a', 255));
});

// ─── Cib Component Rendering ───────────────────────────

test('groups render as cib cards with stat pills', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create(['is_active' => true]);
    UserRule::factory()->for($this->user)->for($group, 'group')->create();

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->assertSeeHtml('class="cib-card"')
        ->assertSeeHtml('class="stat-pill stat-pill-neutral"')
        ->assertSee('1 rule');
});

test('inactive and stop-processing groups show tone pills', function () {
    UserRuleGroup::factory()
        ->for($this->user)
        ->create(['is_active' => false]);

    UserRuleGroup::factory()
        ->for($this->user)
        ->create(['stop_processing' => true]);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->assertSeeHtml('class="stat-pill stat-pill-planned"')
        ->assertSee('Inactive')
        ->assertSeeHtml('class="stat-pill stat-pill-posted"')
        ->assertSee('Stop Processing');
});

test('empty state uses the cib component', function () {
    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->assertSeeHtml('class="empty-state"')
        ->assertSee('No rule groups yet');
});

// ─── Search ──────────────────────────────────────────────

test('search shows rules whose name matches', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();
    UserRule::factory()->for($this->user)->for($group, 'group')->create(['name' => 'Coffee shops']);
    UserRule::factory()->for($this->user)->for($group, 'group')->create(['name' => 'Fuel stations']);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->set('search', 'Coffee')
        ->assertSee('Coffee shops')
        ->assertDontSee('Fuel stations');
});

test('search shows rules whose description matches', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();
    UserRule::factory()->for($this->user)->for($group, 'group')->create([
        'name' => 'Groceries',
        'description' => 'Pays the fortnightly butcher',
    ]);
    UserRule::factory()->for($this->user)->for($group, 'group')->create(['name' => 'Fuel stations']);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->set('search', 'butcher')
        ->assertSee('Groceries')
        ->assertSee('Pays the fortnightly butcher')
        ->assertDontSee('Fuel stations');
});

test('search is case insensitive', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();
    UserRule::factory()->for($this->user)->for($group, 'group')->create(['name' => 'Woolworths']);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->set('search', 'WOOL')
        ->assertSee('Woolworths');
});

test('no results heading shows special characters in the search once-escaped', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();
    UserRule::factory()->for($this->user)->for($group, 'group')->create(['name' => 'Coffee shops']);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->set('search', "Domino's & <b>")
        ->assertSeeHtml('No rules match “Domino&#039;s &amp; &lt;b&gt;”')
        ->assertDontSeeHtml('&amp;#039;');
});

test('search with no match shows the no results state', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create(['name' => 'Everyday']);
    UserRule::factory()->for($this->user)->for($group, 'group')->create(['name' => 'Coffee shops']);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->set('search', 'zzz')
        ->assertSee('No rules match')
        ->assertSee('zzz')
        ->assertSee('Clear search')
        ->assertDontSee('No rule groups yet')
        ->assertDontSee('Coffee shops')
        ->assertDontSee('Everyday');
});

test('clearing the search restores all groups', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create(['name' => 'Everyday']);
    UserRule::factory()->for($this->user)->for($group, 'group')->create(['name' => 'Coffee shops']);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->set('search', 'zzz')
        ->set('search', '')
        ->assertDontSee('No rules match')
        ->assertSee('Everyday')
        ->assertSee('Coffee shops');
});

test('search never shows another users rules', function () {
    $other = User::factory()->create();
    $otherGroup = UserRuleGroup::factory()->for($other)->create(['name' => 'Other group']);
    UserRule::factory()->for($other)->for($otherGroup, 'group')->create(['name' => 'Woolworths secret']);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->set('search', 'Woolworths')
        ->assertDontSee('Woolworths secret')
        ->assertDontSee('Other group')
        ->assertSee('No rules match');
});

test('blank and whitespace search show every group including empty ones', function (string $term) {
    $filled = UserRuleGroup::factory()->for($this->user)->create(['name' => 'Filled group']);
    UserRule::factory()->for($this->user)->for($filled, 'group')->create(['name' => 'Coffee shops']);
    UserRuleGroup::factory()->for($this->user)->create(['name' => 'Empty group']);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->set('search', $term)
        ->assertSee('Filled group')
        ->assertSee('Empty group')
        ->assertSee('Coffee shops')
        ->assertDontSee('No rules match');
})->with(['empty' => [''], 'spaces' => ['   ']]);

test('search hides groups without a matching rule and counts matched rules', function () {
    $matching = UserRuleGroup::factory()->for($this->user)->create(['name' => 'Matching group']);
    UserRule::factory()->for($this->user)->for($matching, 'group')->create(['name' => 'Coffee shops']);
    UserRule::factory()->for($this->user)->for($matching, 'group')->create(['name' => 'Coffee beans']);
    UserRule::factory()->for($this->user)->for($matching, 'group')->create(['name' => 'Fuel stations']);
    $other = UserRuleGroup::factory()->for($this->user)->create(['name' => 'Other group']);
    UserRule::factory()->for($this->user)->for($other, 'group')->create(['name' => 'Fuel stations']);
    UserRuleGroup::factory()->for($this->user)->create(['name' => 'Empty group']);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->set('search', 'coffee')
        ->assertSee('Matching group')
        ->assertSee('2 rules')
        ->assertDontSee('Other group')
        ->assertDontSee('Empty group')
        ->assertDontSee('Fuel stations');
});

test('search hides reorder buttons', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();
    UserRule::factory()->for($this->user)->for($group, 'group')->create(['name' => 'Coffee shops']);

    $component = Livewire::actingAs($this->user)->test(UserRuleManager::class);

    $component->assertSeeHtml('moveGroupUp')->assertSeeHtml('moveRuleUp');

    $component->set('search', 'coffee')
        ->assertDontSeeHtml('moveGroupUp')
        ->assertDontSeeHtml('moveRuleUp');
});

test('search does not affect run rules availability', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();
    UserRule::factory()->for($this->user)->for($group, 'group')->create(['name' => 'Coffee shops']);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->set('search', 'zzz')
        ->assertViewHas('hasActiveRules', true);
});

// ─── Test rule ───────────────────────────────────────────

function testRuleSeed(User $user, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => Account::factory()->for($user)->create()->id,
        'description' => 'NETFLIX.COM',
        'category_id' => null,
        'transfer_pair_id' => null,
    ], $overrides));
}

function openedDraft(User $user, Category $category, string $match = 'NETFLIX'): Testable
{
    $group = UserRuleGroup::factory()->for($user)->create();

    return Livewire::actingAs($user)
        ->test(UserRuleManager::class)
        ->call('openAddRuleModal', $group->id)
        ->set('triggers.0.field', RuleTriggerField::Description->value)
        ->set('triggers.0.operator', RuleTriggerOperator::Contains->value)
        ->set('triggers.0.value', $match)
        ->set('actions.0.type', RuleActionType::SetCategory->value)
        ->set('actions.0.value', (string) $category->id);
}

test('test lists what an unsaved rule matches and creates nothing', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    testRuleSeed($this->user);
    testRuleSeed($this->user, ['description' => 'SPOTIFY']);

    $component = openedDraft($this->user, $category)->call('testRule');

    expect($component->get('ruleTestResult')->notYetAppliedCount)->toBe(1)
        ->and(UserRule::where('user_id', $this->user->id)->count())->toBe(0);

    $component->assertSeeHtml('data-testid="rule-test-not-yet"')
        ->assertSee('NETFLIX.COM')
        ->assertDontSee('SPOTIFY');
});

test('test runs without a rule name', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    testRuleSeed($this->user);

    openedDraft($this->user, $category)
        ->set('ruleName', '')
        ->call('testRule')
        ->assertHasNoErrors();
});

test('test reflects the unsaved edits of an existing rule, not the saved triggers', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    $group = UserRuleGroup::factory()->for($this->user)->create();
    $rule = UserRule::factory()->for($this->user)->for($group, 'group')->create([
        'triggers' => [['field' => RuleTriggerField::Description->value, 'operator' => RuleTriggerOperator::Contains->value, 'value' => 'NETFLIX']],
        'actions' => [['type' => RuleActionType::SetCategory->value, 'value' => (string) $category->id]],
    ]);
    testRuleSeed($this->user);
    testRuleSeed($this->user, ['description' => 'SPOTIFY PREMIUM']);

    $component = Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openEditRuleModal', $rule->id)
        ->set('triggers.0.value', 'SPOTIFY')
        ->call('testRule');

    expect($component->get('ruleTestResult')->notYetApplied)->toHaveCount(1)
        ->and($component->get('ruleTestResult')->notYetApplied[0]->name)->toBe('SPOTIFY PREMIUM')
        ->and($rule->fresh()->triggers[0]['value'])->toBe('NETFLIX');
});

test('test shows the three sections, their counts and the hand-categorised marker', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    $other = Category::factory()->create(['is_hidden' => false]);
    testRuleSeed($this->user, ['description' => 'NETFLIX NEW']);
    testRuleSeed($this->user, ['description' => 'NETFLIX DONE', 'category_id' => $category->id, 'category_source' => CategorySource::Rule]);
    testRuleSeed($this->user, ['description' => 'NETFLIX HAND', 'category_id' => $other->id, 'category_source' => CategorySource::Manual]);

    openedDraft($this->user, $category)
        ->call('testRule')
        ->assertSeeHtml('data-testid="rule-test-not-yet"')
        ->assertSeeHtml('data-testid="rule-test-skipped"')
        ->assertSeeHtml('data-testid="rule-test-already"')
        ->assertSee('Not yet applied')
        ->assertSee('Skipped')
        ->assertSee('Already applied')
        ->assertSee('Categorised by hand')
        ->assertSeeHtml('<strong>3</strong>');
});

test('an incomplete trigger shows validation errors instead of running', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    testRuleSeed($this->user);

    openedDraft($this->user, $category)
        ->set('triggers.0.operator', '')
        ->call('testRule')
        ->assertHasErrors(['triggers.0.operator'])
        ->assertSet('ruleTestResult', null);
});

test('a missing action shows validation errors instead of running', function () {
    $category = Category::factory()->create(['is_hidden' => false]);

    openedDraft($this->user, $category)
        ->set('actions.0.type', '')
        ->call('testRule')
        ->assertHasErrors(['actions.0.type'])
        ->assertSet('ruleTestResult', null);
});

test('a failed test clears the previous result', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    testRuleSeed($this->user);

    $component = openedDraft($this->user, $category)->call('testRule');

    expect($component->get('ruleTestResult'))->not->toBeNull();

    $component->set('triggers.0.field', '')->call('testRule')->assertHasErrors(['triggers.0.field'])->assertSet('ruleTestResult', null);
});

test('the result is cleared when the draft or the modal changes', function (Closure $change) {
    $category = Category::factory()->create(['is_hidden' => false]);
    $group = UserRuleGroup::factory()->for($this->user)->create();
    $saved = UserRule::factory()->for($this->user)->for($group, 'group')->create();
    testRuleSeed($this->user);

    $component = openedDraft($this->user, $category)->call('testRule');

    expect($component->get('ruleTestResult'))->not->toBeNull();

    $change($component, $saved);

    $component->assertSet('ruleTestResult', null);
})->with([
    'trigger value' => [fn (Testable $c) => $c->set('triggers.0.value', 'SPOTIFY')],
    'trigger operator' => [fn (Testable $c) => $c->set('triggers.0.operator', RuleTriggerOperator::Equals->value)],
    'strict mode' => [fn (Testable $c) => $c->set('ruleStrictMode', false)],
    'action value' => [fn (Testable $c) => $c->set('actions.0.value', '')],
    'added trigger' => [fn (Testable $c) => $c->call('addTrigger')],
    'removed trigger' => [fn (Testable $c) => $c->call('removeTrigger', 0)],
    'added action' => [fn (Testable $c) => $c->call('addAction')],
    'removed action' => [fn (Testable $c) => $c->call('removeAction', 0)],
    'modal closed' => [fn (Testable $c) => $c->set('showRuleModal', false)],
    'another rule opened' => [fn (Testable $c, UserRule $saved) => $c->call('openEditRuleModal', $saved->id)],
]);

test('the result survives edits that do not change what the rule matches', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    testRuleSeed($this->user);

    $component = openedDraft($this->user, $category)
        ->call('testRule')
        ->set('ruleName', 'Renamed')
        ->set('ruleIsActive', false)
        ->set('ruleIsAutoApply', true);

    expect($component->get('ruleTestResult'))->not->toBeNull();
});

test('test writes nothing and is not audited', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    $row = testRuleSeed($this->user);
    $before = $row->fresh()->getAttributes();
    $draft = openedDraft($this->user, $category);
    $audit = AuditEvent::count();

    $draft->call('testRule');

    expect($row->fresh()->getAttributes())->toBe($before)
        ->and(AuditEvent::count())->toBe($audit)
        ->and(UserRule::count())->toBe(0);
});

test('test only sees the signed-in user\'s transactions', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    testRuleSeed(User::factory()->create());

    $component = openedDraft($this->user, $category)->call('testRule');

    expect($component->get('ruleTestResult')->totalMatches())->toBe(0)
        ->and($component->get('ruleTestResult')->notYetApplied)->toBe([]);
});
