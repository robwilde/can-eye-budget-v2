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
    $group = CategoryGroup::factory()->for($user)->create();
    $category = Category::factory()->for($user)->create();

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
    $user = User::factory()->create();
    $group = CategoryGroup::factory()->for($user)->create();
    $root = Category::factory()->for($user)->create();
    $child = Category::factory()->withParent($root)->create(['group_id' => $group->id]);

    Livewire::actingAs($user)->test(CategoryEditor::class)
        ->call('selectCategory', $child->id)
        ->assertSet('editingGroupId', $group->id)
        ->set('editingGroupId', null)
        ->call('saveCategoryGroup');

    expect($child->fresh()->group_id)->toBeNull();
});

test('an unknown group id is rejected', function () {
    $user = User::factory()->create();
    $category = Category::factory()->for($user)->create();

    Livewire::actingAs($user)->test(CategoryEditor::class)
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
    $user = User::factory()->create();
    $group = CategoryGroup::factory()->for($user)->create(['name' => 'Old', 'color' => '#000000', 'icon' => 'sparkles']);

    Livewire::actingAs($user)->test(CategoryEditor::class)
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
    $user = User::factory()->create();
    CategoryGroup::factory()->for($user)->create(['name' => 'Taken']);
    $own = CategoryGroup::factory()->for($user)->create(['name' => 'Mine']);

    Livewire::actingAs($user)->test(CategoryEditor::class)
        ->call('openGroupForm', $own->id)
        ->set('groupName', 'Taken')
        ->set('groupColor', 'red')
        ->set('groupIcon', 'not-an-icon')
        ->call('saveGroup')
        ->assertHasErrors(['groupName', 'groupColor', 'groupIcon']);

    expect($own->fresh()->name)->toBe('Mine');
});

test('keeping a group name while editing it is not a duplicate', function () {
    $user = User::factory()->create();
    $group = CategoryGroup::factory()->for($user)->create(['name' => 'Same']);

    Livewire::actingAs($user)->test(CategoryEditor::class)
        ->call('openGroupForm', $group->id)
        ->set('groupColor', '#abcdef')
        ->call('saveGroup')
        ->assertHasNoErrors();

    expect($group->fresh()->color)->toBe('#abcdef');
});

test('the list shows the effective group of inheriting and overriding categories', function () {
    $user = User::factory()->create();
    $food = CategoryGroup::factory()->for($user)->create(['name' => 'Food Group']);
    $savings = CategoryGroup::factory()->for($user)->create(['name' => 'Savings Group']);
    $root = Category::factory()->for($user)->create(['group_id' => $food->id]);
    $child = Category::factory()->withParent($root)->create(['name' => 'Moved', 'group_id' => $savings->id]);
    $inheriting = Category::factory()->withParent($root)->create(['name' => 'Stays']);

    Livewire::actingAs($user)->test(CategoryEditor::class)
        ->assertViewHas('categories', fn ($rows) => $rows->firstWhere('id', $child->id)['group']->id === $savings->id
            && $rows->firstWhere('id', $inheriting->id)['group']->id === $food->id);
});
