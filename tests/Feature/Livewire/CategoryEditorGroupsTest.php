<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Livewire\CategoryEditor;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\User;
use Livewire\Livewire;

test('a user can change a category group and the change is audited', function () {
    $user = User::factory()->create();
    $group = CategoryGroup::factory()->create();
    $category = Category::factory()->create();

    Livewire::actingAs($user)->test(CategoryEditor::class)
        ->call('selectCategory', $category->id)
        ->set('editingGroupId', $group->id)
        ->call('saveCategoryGroup');

    $event = AuditEvent::query()->where('action', 'livewire.category-editor.saveCategoryGroup')->sole();

    expect($category->fresh()->group_id)->toBe($group->id)
        ->and($event->user_id)->toBe($user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});

test('a child can override its root group and be put back to inherit', function () {
    $group = CategoryGroup::factory()->create();
    $root = Category::factory()->create();
    $child = Category::factory()->withParent($root)->create(['group_id' => $group->id]);

    Livewire::actingAs(User::factory()->create())->test(CategoryEditor::class)
        ->call('selectCategory', $child->id)
        ->assertSet('editingGroupId', $group->id)
        ->set('editingGroupId', null)
        ->call('saveCategoryGroup');

    expect($child->fresh()->group_id)->toBeNull();
});

test('an unknown group id is rejected', function () {
    $category = Category::factory()->create();

    Livewire::actingAs(User::factory()->create())->test(CategoryEditor::class)
        ->call('selectCategory', $category->id)
        ->set('editingGroupId', 999999)
        ->call('saveCategoryGroup')
        ->assertHasErrors('editingGroupId');

    expect($category->fresh()->group_id)->toBeNull();
});

test('a user can create a group and it is audited', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(CategoryEditor::class)
        ->call('openGroupForm')
        ->set('groupName', 'Hobbies')
        ->set('groupColor', '#112233')
        ->set('groupIcon', 'gift')
        ->call('saveGroup')
        ->assertHasNoErrors();

    $group = CategoryGroup::query()->where('name', 'Hobbies')->sole();

    expect($group->color)->toBe('#112233')
        ->and($group->icon)->toBe('gift')
        ->and(AuditEvent::query()->where('action', 'livewire.category-editor.saveGroup')->sole()->outcome)->toBe(AuditOutcome::Success);
});

test('a user can rename, recolour and re-icon a group', function () {
    $group = CategoryGroup::factory()->create(['name' => 'Old', 'color' => '#000000', 'icon' => 'sparkles']);

    Livewire::actingAs(User::factory()->create())->test(CategoryEditor::class)
        ->call('openGroupForm', $group->id)
        ->assertSet('groupName', 'Old')
        ->set('groupName', 'New')
        ->set('groupColor', '#ffffff')
        ->set('groupIcon', 'truck')
        ->call('saveGroup')
        ->assertHasNoErrors();

    expect($group->fresh()->only(['name', 'color', 'icon']))->toBe(['name' => 'New', 'color' => '#ffffff', 'icon' => 'truck']);
});

test('group validation rejects a duplicate name, a bad colour and an unknown icon', function () {
    CategoryGroup::factory()->create(['name' => 'Taken']);
    $own = CategoryGroup::factory()->create(['name' => 'Mine']);

    Livewire::actingAs(User::factory()->create())->test(CategoryEditor::class)
        ->call('openGroupForm', $own->id)
        ->set('groupName', 'Taken')
        ->set('groupColor', 'red')
        ->set('groupIcon', 'not-an-icon')
        ->call('saveGroup')
        ->assertHasErrors(['groupName', 'groupColor', 'groupIcon']);

    expect($own->fresh()->name)->toBe('Mine');
});

test('keeping a group name while editing it is not a duplicate', function () {
    $group = CategoryGroup::factory()->create(['name' => 'Same']);

    Livewire::actingAs(User::factory()->create())->test(CategoryEditor::class)
        ->call('openGroupForm', $group->id)
        ->set('groupColor', '#abcdef')
        ->call('saveGroup')
        ->assertHasNoErrors();

    expect($group->fresh()->color)->toBe('#abcdef');
});

test('the list shows the effective group of inheriting and overriding categories', function () {
    $food = CategoryGroup::factory()->create(['name' => 'Food Group']);
    $savings = CategoryGroup::factory()->create(['name' => 'Savings Group']);
    $root = Category::factory()->create(['group_id' => $food->id]);
    $child = Category::factory()->withParent($root)->create(['name' => 'Moved', 'group_id' => $savings->id]);
    $inheriting = Category::factory()->withParent($root)->create(['name' => 'Stays']);

    Livewire::actingAs(User::factory()->create())->test(CategoryEditor::class)
        ->assertViewHas('categories', fn ($rows) => $rows->firstWhere('id', $child->id)['group']->id === $savings->id
            && $rows->firstWhere('id', $inheriting->id)['group']->id === $food->id);
});
