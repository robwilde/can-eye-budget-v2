<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\RuleTestRow;
use App\Enums\CategorySource;
use App\Enums\RuleActionType;
use App\Enums\RuleTestSkipReason;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\RuleTester;
use App\Services\UserRuleApplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->tester = app(RuleTester::class);
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['name' => 'Everyday']);
    $this->category = Category::factory()->for($this->user)->create(['is_hidden' => false]);
});

function testerTxn(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'NETFLIX.COM',
        'merchant_name' => null,
        'clean_description' => null,
        'category_id' => null,
        'amount' => 1899,
        'direction' => TransactionDirection::Debit,
        'post_date' => CarbonImmutable::parse('2026-06-10'),
        'transfer_pair_id' => null,
        'notes' => null,
    ], $overrides));
}

/**
 * @return array<int, array<string, string>>
 */
function testerTrigger(string $value = 'NETFLIX'): array
{
    return [[
        'field' => RuleTriggerField::Description->value,
        'operator' => RuleTriggerOperator::Contains->value,
        'value' => $value,
    ]];
}

/**
 * @return array<int, array<string, string>>
 */
function testerCategoryAction(int $categoryId): array
{
    return [['type' => RuleActionType::SetCategory->value, 'value' => (string) $categoryId]];
}

function testerIds(array $rows): array
{
    return array_map(fn (RuleTestRow $row): int => $row->id, $rows);
}

test('an uncategorised match is not yet applied and a row already in the target category is already applied', function () {
    $uncategorised = testerTxn($this->user, $this->account);
    $done = testerTxn($this->user, $this->account, ['category_id' => $this->category->id, 'category_source' => CategorySource::Rule]);
    testerTxn($this->user, $this->account, ['description' => 'SPOTIFY']);

    $result = $this->tester->test($this->user->id, testerTrigger(), true, testerCategoryAction($this->category->id));

    expect($result->notYetAppliedCount)->toBe(1)
        ->and(testerIds($result->notYetApplied))->toBe([$uncategorised->id])
        ->and($result->alreadyAppliedCount)->toBe(1)
        ->and(testerIds($result->alreadyApplied))->toBe([$done->id])
        ->and($result->skippedCount)->toBe(0)
        ->and($result->totalMatches())->toBe(2);
});

test('a row a person filed elsewhere is skipped and counted apart from the others', function () {
    $other = Category::factory()->for($this->user)->create(['is_hidden' => false]);
    $hand = testerTxn($this->user, $this->account, ['category_id' => $other->id, 'category_source' => CategorySource::Manual]);
    testerTxn($this->user, $this->account);

    $result = $this->tester->test($this->user->id, testerTrigger(), true, testerCategoryAction($this->category->id));

    expect($result->skippedCount)->toBe(1)
        ->and(testerIds($result->skipped))->toBe([$hand->id])
        ->and($result->skipped[0]->skipReason)->toBe(RuleTestSkipReason::CategorisedByHand)
        ->and($result->skipped[0]->categoryPath)->toBe($other->fullPath())
        ->and($result->notYetAppliedCount)->toBe(1);
});

test('a row a person filed under the target category is already applied, not skipped', function () {
    testerTxn($this->user, $this->account, ['category_id' => $this->category->id, 'category_source' => CategorySource::Manual]);

    $result = $this->tester->test($this->user->id, testerTrigger(), true, testerCategoryAction($this->category->id));

    expect($result->alreadyAppliedCount)->toBe(1)
        ->and($result->skippedCount)->toBe(0);
});

test('a hand-categorised row another action would change is not yet applied with its category kept', function () {
    $other = Category::factory()->for($this->user)->create(['is_hidden' => false]);
    $hand = testerTxn($this->user, $this->account, ['category_id' => $other->id, 'category_source' => CategorySource::Manual]);

    $result = $this->tester->test($this->user->id, testerTrigger(), true, [
        ...testerCategoryAction($this->category->id),
        ['type' => RuleActionType::SetCleanDescription->value, 'value' => 'Netflix'],
    ]);

    expect($result->notYetAppliedCount)->toBe(1)
        ->and($result->notYetApplied[0]->id)->toBe($hand->id)
        ->and($result->notYetApplied[0]->categoryKept)->toBeTrue()
        ->and($result->skippedCount)->toBe(0);
});

test('a name that is already set is already applied and a different one is not yet applied', function () {
    $named = testerTxn($this->user, $this->account, ['clean_description' => 'Netflix', 'clean_description_source' => 'rule']);
    $other = testerTxn($this->user, $this->account, ['clean_description' => 'Mine', 'clean_description_source' => 'manual']);

    $result = $this->tester->test($this->user->id, testerTrigger(), true, [
        ['type' => RuleActionType::SetCleanDescription->value, 'value' => 'Netflix'],
    ]);

    expect(testerIds($result->alreadyApplied))->toBe([$named->id])
        ->and(testerIds($result->notYetApplied))->toBe([$other->id]);
});

test('descriptions and notes are judged against what the row already holds', function (array $action, array $attributes, bool $changes) {
    $row = testerTxn($this->user, $this->account, $attributes);

    $result = $this->tester->test($this->user->id, testerTrigger(), true, [$action]);

    expect($result->notYetAppliedCount)->toBe($changes ? 1 : 0)
        ->and($result->alreadyAppliedCount)->toBe($changes ? 0 : 1)
        ->and($row->fresh()->notes)->toBe($attributes['notes'] ?? null);
})->with([
    'description differs' => [['type' => 'set_description', 'value' => 'OTHER'], [], true],
    'description same' => [['type' => 'set_description', 'value' => 'NETFLIX.COM'], [], false],
    'notes differ' => [['type' => 'set_notes', 'value' => 'new'], ['notes' => 'old'], true],
    'notes same' => [['type' => 'set_notes', 'value' => 'same'], ['notes' => 'same'], false],
]);

test('a planned link is not yet applied until the row carries it, and another user\'s plan changes nothing', function () {
    $own = PlannedTransaction::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->account->id]);
    $foreign = PlannedTransaction::factory()->create(['user_id' => User::factory()->create()->id]);
    testerTxn($this->user, $this->account);

    $linked = $this->tester->test($this->user->id, testerTrigger(), true, [['type' => 'link_to_planned_transaction', 'value' => (string) $own->id]]);
    $foreignLink = $this->tester->test($this->user->id, testerTrigger(), true, [['type' => 'link_to_planned_transaction', 'value' => (string) $foreign->id]]);

    expect($linked->notYetAppliedCount)->toBe(1)
        ->and($foreignLink->alreadyAppliedCount)->toBe(1);
});

test('append_notes is never already applied and the result says so', function () {
    testerTxn($this->user, $this->account, ['notes' => 'note']);

    $result = $this->tester->test($this->user->id, testerTrigger(), true, [['type' => 'append_notes', 'value' => 'note']]);

    expect($result->notYetAppliedCount)->toBe(1)
        ->and($result->alreadyAppliedCount)->toBe(0)
        ->and($result->appendsNotes)->toBeTrue();
});

test('a fee with a parent is not yet applied and an orphan fee is skipped', function () {
    $fee = testerTxn($this->user, $this->account, [
        'description' => 'Int Tran Fee - JetBrains CZ - 951280',
        'amount' => -42,
        'post_date' => CarbonImmutable::parse('2026-07-05'),
    ]);
    $orphan = testerTxn($this->user, $this->account, [
        'description' => 'Int Tran Fee - Other CZ - 123456',
        'amount' => -42,
        'post_date' => CarbonImmutable::parse('2026-07-06'),
    ]);
    testerTxn($this->user, $this->account, [
        'description' => 'VISA -JetBrains CZ FRGN AMT 051280 #8357',
        'amount' => -1394,
        'post_date' => CarbonImmutable::parse('2026-07-05'),
    ]);

    $result = $this->tester->test($this->user->id, testerTrigger('Int Tran Fee'), true, [['type' => 'fold_into_parent', 'value' => '']]);

    expect(testerIds($result->notYetApplied))->toBe([$fee->id])
        ->and(testerIds($result->skipped))->toBe([$orphan->id])
        ->and($result->skipped[0]->skipReason)->toBe(RuleTestSkipReason::FoldNotPossible)
        ->and($result->alreadyAppliedCount)->toBe(0);
});

test('strict mode needs every trigger and any mode needs one', function () {
    $both = testerTxn($this->user, $this->account, ['description' => 'NETFLIX MONTHLY']);
    $one = testerTxn($this->user, $this->account, ['description' => 'NETFLIX ANNUAL']);
    $triggers = [
        ...testerTrigger('NETFLIX'),
        ...testerTrigger('MONTHLY'),
    ];

    $strict = $this->tester->test($this->user->id, $triggers, true, testerCategoryAction($this->category->id));
    $any = $this->tester->test($this->user->id, $triggers, false, testerCategoryAction($this->category->id));

    expect(testerIds($strict->notYetApplied))->toBe([$both->id])
        ->and($any->notYetAppliedCount)->toBe(2)
        ->and(testerIds($any->notYetApplied))->toContain($both->id, $one->id);
});

test('only the current version of a row is tested', function () {
    $original = testerTxn($this->user, $this->account);
    $child = $original->createChild(['description' => 'NETFLIX.COM RENEWED']);

    $result = $this->tester->test($this->user->id, testerTrigger(), true, testerCategoryAction($this->category->id));

    expect(testerIds($result->notYetApplied))->toBe([$child->id]);
});

test('transfers and split parents are left out', function () {
    $partner = testerTxn($this->user, $this->account, ['description' => 'OTHER']);
    testerTxn($this->user, $this->account, ['transfer_pair_id' => $partner->id]);
    $split = testerTxn($this->user, $this->account);
    $split->splits()->create(['category_id' => Category::factory()->for($this->user)->create(['is_hidden' => false])->id, 'amount' => 1899, 'position' => 1]);

    $result = $this->tester->test($this->user->id, testerTrigger(), true, testerCategoryAction($this->category->id));

    expect($result->totalMatches())->toBe(0);
});

test('another user\'s transactions never appear', function () {
    $stranger = User::factory()->create();
    testerTxn($stranger, Account::factory()->for($stranger)->create());
    $mine = testerTxn($this->user, $this->account);

    $result = $this->tester->test($this->user->id, testerTrigger(), true, testerCategoryAction($this->category->id));

    expect(testerIds($result->notYetApplied))->toBe([$mine->id])
        ->and($result->totalMatches())->toBe(1);
});

test('a long match list keeps the full counts but only the newest fifty rows, newest first', function () {
    Transaction::factory()->count(55)
        ->sequence(fn ($sequence): array => ['post_date' => CarbonImmutable::parse('2026-01-01')->addDays($sequence->index)])
        ->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'description' => 'NETFLIX.COM',
            'category_id' => null,
            'transfer_pair_id' => null,
        ]);

    $result = $this->tester->test($this->user->id, testerTrigger(), true, testerCategoryAction($this->category->id));

    expect($result->notYetAppliedCount)->toBe(55)
        ->and($result->notYetApplied)->toHaveCount(50)
        ->and($result->notYetApplied[0]->postDate)->toBe('2026-02-24')
        ->and($result->notYetApplied[49]->postDate)->toBe('2026-01-06');
});

test('a row carries what the editor lists', function () {
    testerTxn($this->user, $this->account, ['clean_description' => 'Netflix', 'amount' => -1899, 'direction' => TransactionDirection::Debit]);

    $row = $this->tester->test($this->user->id, testerTrigger(), true, testerCategoryAction($this->category->id))->notYetApplied[0];

    expect($row->name)->toBe('Netflix')
        ->and($row->amount)->toBe(1899)
        ->and($row->accountName)->toBe('Everyday')
        ->and($row->categoryPath)->toBeNull()
        ->and($row->postDate)->toBe('2026-06-10');
});

test('testing writes nothing', function () {
    testerTxn($this->user, $this->account);
    testerTxn($this->user, $this->account, ['description' => 'NETFLIX 2']);
    $before = Transaction::withTrashed()->orderBy('id')->get()->map->getAttributes()->all();
    $audit = AuditEvent::count();

    $this->tester->test($this->user->id, testerTrigger(), true, [
        ...testerCategoryAction($this->category->id),
        ['type' => 'set_clean_description', 'value' => 'Netflix'],
        ['type' => 'append_notes', 'value' => 'x'],
    ]);

    expect(Transaction::withTrashed()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before)
        ->and(UserRule::count())->toBe(0)
        ->and(AuditEvent::count())->toBe($audit);
});

test('after Apply now every row that was not yet applied tests as already applied', function () {
    $other = Category::factory()->for($this->user)->create(['is_hidden' => false]);
    $fresh = testerTxn($this->user, $this->account);
    $renamed = testerTxn($this->user, $this->account, ['category_id' => $this->category->id, 'category_source' => CategorySource::Rule, 'clean_description' => 'Old', 'clean_description_source' => 'rule']);
    $hand = testerTxn($this->user, $this->account, ['category_id' => $other->id, 'category_source' => CategorySource::Manual]);
    $actions = [
        ...testerCategoryAction($this->category->id),
        ['type' => 'set_clean_description', 'value' => 'Netflix'],
    ];

    $before = $this->tester->test($this->user->id, testerTrigger(), true, $actions);

    $group = UserRuleGroup::factory()->for($this->user)->create();
    $rule = UserRule::factory()->for($this->user)->for($group, 'group')->create([
        'triggers' => testerTrigger(),
        'actions' => $actions,
        'strict_mode' => true,
    ]);
    app(UserRuleApplier::class)->applyToHistory($rule);

    $after = $this->tester->test($this->user->id, testerTrigger(), true, $actions);

    expect($before->notYetAppliedCount)->toBe(3)
        ->and($after->notYetAppliedCount)->toBe(0)
        ->and(testerIds($after->alreadyApplied))->toEqualCanonicalizing([$fresh->id, $renamed->id])
        ->and(testerIds($after->skipped))->toBe([$hand->id]);
});

test('a broad rule checks each category and plan once, not once per matching row', function () {
    $plan = PlannedTransaction::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->account->id]);
    Transaction::factory()->count(20)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'NETFLIX.COM',
        'category_id' => null,
        'transfer_pair_id' => null,
        'planned_transaction_id' => null,
    ]);

    DB::enableQueryLog();

    $result = $this->tester->test($this->user->id, testerTrigger(), true, [
        ...testerCategoryAction($this->category->id),
        ['type' => RuleActionType::LinkToPlannedTransaction->value, 'value' => (string) $plan->id],
    ]);

    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($result->notYetAppliedCount)->toBe(20)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'categories') && str_contains($sql, 'is_hidden'))->count())->toBeLessThanOrEqual(2)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'planned_transactions'))->count())->toBeLessThanOrEqual(1);
});
