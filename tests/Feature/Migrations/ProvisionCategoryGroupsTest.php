<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BudgetTag;
use App\Models\Budget;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategoryGroupProvisioner;
use Database\Seeders\CategorySeeder;

function provisionCategoryGroups(): void
{
    (require database_path('migrations/2026_10_20_000100_provision_category_groups.php'))->up();
}

function groupTestTree(): array
{
    $roots = [];

    foreach (array_keys(CategoryGroupProvisioner::ROOT_GROUPS) as $name) {
        $roots[$name] = Category::factory()->create(['name' => $name, 'budget_tag' => BudgetTag::Needs]);
    }

    $roots['Balance'] = Category::factory()->create(['name' => 'Balance']);

    return $roots;
}

test('every budget root plus Income and Transfer ends up in exactly one group with a colour and icon', function () {
    $roots = groupTestTree();

    provisionCategoryGroups();

    $assigned = collect($roots)->except('Balance')->map(fn (Category $root) => $root->fresh()->effectiveGroup());

    expect($assigned)->each(fn (Pest\Expectation $group) => $group->toBeInstanceOf(CategoryGroup::class))
        ->and($assigned->pluck('color')->filter()->count())->toBe(count(CategoryGroupProvisioner::ROOT_GROUPS))
        ->and($assigned->pluck('icon')->filter()->count())->toBe(count(CategoryGroupProvisioner::ROOT_GROUPS))
        ->and($roots['Balance']->fresh()->group_id)->toBeNull();
});

test('the roots land in the groups the issue maps them to', function () {
    $roots = groupTestTree();

    provisionCategoryGroups();

    expect($roots['Housing & Utilities']->fresh()->effectiveGroup()->name)->toBe('Bills')
        ->and($roots['Eating Out']->fresh()->effectiveGroup()->name)->toBe('Food')
        ->and($roots['Work Equipment']->fresh()->effectiveGroup()->name)->toBe('Office')
        ->and($roots['Learning & Reading']->fresh()->effectiveGroup()->name)->toBe('Training')
        ->and($roots['Pets']->fresh()->effectiveGroup()->name)->toBe('Personal')
        ->and($roots['Bank Fees & Finance Services']->fresh()->effectiveGroup()->name)->toBe('Personal')
        ->and($roots['Health']->fresh()->effectiveGroup()->name)->toBe('Health')
        ->and($roots['Loans & Debt Repayment']->fresh()->effectiveGroup()->name)->toBe('Loan');
});

test('savings and investment transfer children are overridden while other transfer children keep Transfer', function () {
    $roots = groupTestTree();
    $spaceship = Category::factory()->withParent($roots['Transfer'])->create(['name' => 'Optimus to Spaceship']);
    $card = Category::factory()->withParent($roots['Transfer'])->create(['name' => 'Optimus to CC']);

    provisionCategoryGroups();

    expect($spaceship->fresh()->effectiveGroup()->name)->toBe('Savings & Investments')
        ->and($card->fresh()->effectiveGroup()->name)->toBe('Transfer');
});

test('the seeded category tree is fully grouped except Balance and routes the seeded savings transfers to Savings & Investments', function () {
    $this->seed(CategorySeeder::class);

    $ungroupedRoots = Category::query()->whereNull('parent_id')->whereNull('group_id')->pluck('name')->all();
    $groupOf = fn (string $name): ?string => Category::query()->where('name', $name)->whereNotNull('parent_id')->firstOrFail()->effectiveGroup()?->name;

    expect($ungroupedRoots)->toBe(['Balance'])
        ->and($groupOf('Optimus to Spaceship'))->toBe(CategoryGroupProvisioner::SAVINGS_GROUP)
        ->and($groupOf('uBank to uSavings'))->toBe(CategoryGroupProvisioner::SAVINGS_GROUP)
        ->and($groupOf('Optimus to CC'))->toBe('Transfer');
});

test('no category id, budget tag, hierarchy or reference changes', function () {
    $roots = groupTestTree();
    $child = Category::factory()->withParent($roots['Groceries'])->create(['name' => 'Fruit']);
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->create(['category_id' => $child->id]);
    $budget = Budget::factory()->for($user)->create(['category_id' => $roots['Groceries']->id]);

    $before = Category::query()->orderBy('id')->get(['id', 'name', 'parent_id', 'budget_tag'])->toArray();

    provisionCategoryGroups();

    expect(Category::query()->orderBy('id')->get(['id', 'name', 'parent_id', 'budget_tag'])->toArray())->toBe($before)
        ->and($transaction->fresh()->category_id)->toBe($child->id)
        ->and($budget->fresh()->category_id)->toBe($roots['Groceries']->id);
});

test('running the migration again changes nothing, including a group the user reassigned', function () {
    $roots = groupTestTree();
    provisionCategoryGroups();

    $own = CategoryGroup::factory()->create(['name' => 'Mine']);
    $roots['Groceries']->update(['group_id' => $own->id]);
    $groupCount = CategoryGroup::query()->count();

    provisionCategoryGroups();

    expect(CategoryGroup::query()->count())->toBe($groupCount)
        ->and($roots['Groceries']->fresh()->group_id)->toBe($own->id);
});

test('an empty categories table is left alone', function () {
    provisionCategoryGroups();

    expect(CategoryGroup::query()->count())->toBe(0);
});
