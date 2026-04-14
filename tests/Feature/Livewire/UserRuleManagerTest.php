<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Livewire\UserRuleManager;
use App\Models\Category;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
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
