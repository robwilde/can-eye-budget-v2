<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BnplOrderEventType;
use App\Enums\BudgetTag;
use App\Enums\CategorySource;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Exceptions\CategoriesLockedException;
use App\Exceptions\CategoryMergeRefusedException;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\BnplOrder;
use App\Models\Budget;
use App\Models\BudgetPlan;
use App\Models\Category;
use App\Models\Payee;
use App\Models\PipelineRun;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Models\UserCategoryBudgetTag;
use App\Models\UserRule;
use App\Services\CategoryCreator;
use App\Services\CategoryMerger;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->merger = app(CategoryMerger::class);
});

function mergerCategory(User $user, string $name, ?Category $parent = null): Category
{
    return Category::factory()->for($user)->create(['name' => $name, 'parent_id' => $parent?->id]);
}

function mergerTransactions(User $user, Account $account, Category $category, int $count): void
{
    Transaction::factory()->for($user)->for($account)->manual()->count($count)->create(['category_id' => $category->id]);
}

function mergerRule(User $user, array $triggers, array $actions, bool $strict = true): UserRule
{
    return UserRule::factory()->for($user)->create(['triggers' => $triggers, 'actions' => $actions, 'strict_mode' => $strict, 'is_active' => true]);
}

it('sums budgets and moves transactions when merging categories', function () {
    $a = mergerCategory($this->user, 'Alpha');
    $b = mergerCategory($this->user, 'Beta');
    $plan = BudgetPlan::factory()->for($this->user)->create();
    $lineA = Budget::factory()->for($this->user)->create(['budget_plan_id' => $plan->id, 'category_id' => $a->id, 'limit_amount' => 20000, 'rolled_over_amount' => 100, 'rollover_enabled' => true]);
    Budget::factory()->for($this->user)->create(['budget_plan_id' => $plan->id, 'category_id' => $b->id, 'limit_amount' => 10000, 'rolled_over_amount' => 50, 'rollover_enabled' => false]);
    mergerTransactions($this->user, $this->account, $b, 3);

    $this->merger->merge($this->user, [$b->id], $a->id);

    $lines = Budget::query()->where('user_id', $this->user->id)->get();

    expect(Category::query()->whereKey($b->id)->exists())->toBeFalse()
        ->and($lines)->toHaveCount(1)
        ->and($lines->first()->id)->toBe($lineA->id)
        ->and($lines->first()->limit_amount)->toBe(30000)
        ->and($lines->first()->rolled_over_amount)->toBe(150)
        ->and($lines->first()->rollover_enabled)->toBeTrue()
        ->and(Transaction::query()->where('user_id', $this->user->id)->where('category_id', $a->id)->count())->toBe(3);
});

it('keeps a shared budget line shared only when both lines are shared', function () {
    $a = mergerCategory($this->user, 'Alpha');
    $b = mergerCategory($this->user, 'Beta');
    $c = mergerCategory($this->user, 'Gamma');
    $plan = BudgetPlan::factory()->for($this->user)->create();
    Budget::factory()->for($this->user)->create(['budget_plan_id' => $plan->id, 'category_id' => $a->id, 'limit_amount' => null]);
    Budget::factory()->for($this->user)->create(['budget_plan_id' => $plan->id, 'category_id' => $b->id, 'limit_amount' => null]);
    Budget::factory()->for($this->user)->create(['budget_plan_id' => $plan->id, 'category_id' => $c->id, 'limit_amount' => 700]);

    $this->merger->merge($this->user, [$b->id], $a->id);

    expect(Budget::query()->where('category_id', $a->id)->value('limit_amount'))->toBeNull();

    $this->merger->merge($this->user, [$c->id], $a->id);

    expect(Budget::query()->where('category_id', $a->id)->value('limit_amount'))->toBe(700);
});

it('re-points budget lines, planned rows and BNPL orders that have no clash', function () {
    $a = mergerCategory($this->user, 'Alpha');
    $b = mergerCategory($this->user, 'Beta');
    $plan = BudgetPlan::factory()->for($this->user)->create();
    $line = Budget::factory()->for($this->user)->create(['budget_plan_id' => $plan->id, 'category_id' => $b->id]);
    $planned = PlannedTransaction::factory()->for($this->user)->create(['category_id' => $b->id]);
    $order = BnplOrder::factory()->for($this->user)->create(['category_id' => $b->id]);
    $split = TransactionSplit::factory()->create([
        'transaction_id' => Transaction::factory()->for($this->user)->for($this->account)->create()->id,
        'category_id' => $b->id,
    ]);

    $this->merger->merge($this->user, [$b->id], $a->id);

    expect($line->fresh()->category_id)->toBe($a->id)
        ->and($planned->fresh()->category_id)->toBe($a->id)
        ->and($order->fresh()->category_id)->toBe($a->id)
        ->and($split->fresh()->category_id)->toBe($a->id);
});

it('keeps the category source when merging', function () {
    $a = mergerCategory($this->user, 'Alpha');
    $b = mergerCategory($this->user, 'Beta');
    $transaction = Transaction::factory()->for($this->user)->for($this->account)->create(['category_id' => $b->id, 'category_source' => CategorySource::Rule]);

    $this->merger->merge($this->user, [$b->id], $a->id);

    expect($transaction->fresh()->category_id)->toBe($a->id)
        ->and($transaction->fresh()->category_source)->toBe(CategorySource::Rule);
});

it('rewrites payee, tag, suggestion and BNPL event references on merge', function () {
    $a = mergerCategory($this->user, 'Alpha');
    $b = mergerCategory($this->user, 'Beta');
    $payee = Payee::factory()->for($this->user)->create([
        'suggested_category_id' => $b->id,
        'confirmed_category_id' => $b->id,
        'probabilities' => [$a->id => 0.25, $b->id => 0.5, 99 => 0.25],
    ]);
    UserCategoryBudgetTag::query()->create(['user_id' => $this->user->id, 'category_id' => $a->id, 'budget_tag' => BudgetTag::Needs]);
    $tag = UserCategoryBudgetTag::query()->create(['user_id' => $this->user->id, 'category_id' => $b->id, 'budget_tag' => BudgetTag::Wants]);
    $run = PipelineRun::factory()->for($this->user)->create();
    $recurring = AnalysisSuggestion::factory()->create(['user_id' => $this->user->id, 'pipeline_run_id' => $run->id, 'type' => SuggestionType::RecurringTransaction, 'payload' => ['category_id' => $b->id, 'amount' => 10]]);
    $ruleSuggestion = AnalysisSuggestion::factory()->create(['user_id' => $this->user->id, 'pipeline_run_id' => $run->id, 'type' => SuggestionType::UserRule, 'payload' => ['actions' => [['type' => 'set_category', 'value' => (string) $b->id]]]]);
    $order = BnplOrder::factory()->for($this->user)->create();
    $event = $order->events()->create(['event' => BnplOrderEventType::CategorySet, 'payload' => ['category_id' => $b->id, 'previous_category_id' => (string) $b->id], 'actor' => 'system']);

    $this->merger->merge($this->user, [$b->id], $a->id);

    expect($payee->fresh()->suggested_category_id)->toBe($a->id)
        ->and($payee->fresh()->confirmed_category_id)->toBe($a->id)
        ->and($payee->fresh()->probabilities)->toEqual([$a->id => 0.75, 99 => 0.25])
        ->and(UserCategoryBudgetTag::query()->where('user_id', $this->user->id)->pluck('category_id')->all())->toBe([$a->id])
        ->and(UserCategoryBudgetTag::query()->whereKey($tag->id)->exists())->toBeFalse()
        ->and($recurring->fresh()->payload['category_id'])->toBe($a->id)
        ->and($ruleSuggestion->fresh()->payload['actions'][0]['value'])->toBe((string) $a->id)
        ->and($event->fresh()->payload)->toBe(['category_id' => $a->id, 'previous_category_id' => (string) $a->id]);
});

it('rewrites rule category references on merge', function () {
    $a = mergerCategory($this->user, 'Alpha');
    $b = mergerCategory($this->user, 'Beta');
    $rule = mergerRule(
        $this->user,
        [['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX'], ['field' => 'category_id', 'operator' => 'equals', 'value' => (string) $b->id]],
        [['type' => 'set_category', 'value' => (string) $b->id], ['type' => 'set_notes', 'value' => (string) $b->id]],
    );

    $this->merger->merge($this->user, [$b->id], $a->id);

    expect($rule->fresh()->actions)->toBe([['type' => 'set_category', 'value' => (string) $a->id], ['type' => 'set_notes', 'value' => (string) $b->id]])
        ->and($rule->fresh()->triggers[1]['value'])->toBe((string) $a->id)
        ->and($rule->fresh()->triggers[0]['value'])->toBe('NETFLIX');
});

it('refuses to merge a parent into its own subcategory', function () {
    $parent = mergerCategory($this->user, 'Food');
    $child = mergerCategory($this->user, 'Coffee', $parent);
    mergerTransactions($this->user, $this->account, $parent, 2);

    expect(fn () => $this->merger->merge($this->user, [$parent->id], $child->id))->toThrow(CategoryMergeRefusedException::class);

    expect(Category::query()->where('user_id', $this->user->id)->count())->toBe(2)
        ->and($child->fresh()->parent_id)->toBe($parent->id)
        ->and(Transaction::query()->where('category_id', $parent->id)->count())->toBe(2);
});

it('refuses a merge into itself, into nothing, or of a protected category', function () {
    $a = mergerCategory($this->user, 'Alpha');
    $transfer = mergerCategory($this->user, 'Transfer');

    expect(fn () => $this->merger->merge($this->user, [$a->id], $a->id))->toThrow(CategoryMergeRefusedException::class)
        ->and(fn () => $this->merger->merge($this->user, [], $a->id))->toThrow(CategoryMergeRefusedException::class)
        ->and(fn () => $this->merger->merge($this->user, [$transfer->id], $a->id))->toThrow(CategoryMergeRefusedException::class)
        ->and(fn () => $this->merger->deleteWithReplacement($this->user, $transfer->id))->toThrow(CategoryMergeRefusedException::class)
        ->and(Category::query()->whereKey($transfer->id)->exists())->toBeTrue();
});

it('refuses to merge a category with subcategories into a subcategory', function () {
    $root = mergerCategory($this->user, 'Food');
    $subcategory = mergerCategory($this->user, 'Coffee', $root);
    $other = mergerCategory($this->user, 'Home');
    mergerCategory($this->user, 'Tools', $other);

    expect(fn () => $this->merger->merge($this->user, [$other->id], $subcategory->id))->toThrow(CategoryMergeRefusedException::class)
        ->and(Category::query()->where('user_id', $this->user->id)->count())->toBe(4);
});

it('refuses ids outside the acting user\'s categories', function () {
    $a = mergerCategory($this->user, 'Alpha');
    $stranger = mergerCategory(User::factory()->create(), 'Beta');

    expect(fn () => $this->merger->merge($this->user, [$stranger->id], $a->id))->toThrow(CategoryMergeRefusedException::class)
        ->and(fn () => $this->merger->merge($this->user, [$a->id], $stranger->id))->toThrow(CategoryMergeRefusedException::class)
        ->and(fn () => $this->merger->deleteWithReplacement($this->user, $a->id, $stranger->id))->toThrow(CategoryMergeRefusedException::class)
        ->and(fn () => $this->merger->deleteWithReplacement($this->user, $stranger->id))->toThrow(CategoryMergeRefusedException::class)
        ->and(Category::query()->whereKey([$a->id, $stranger->id])->count())->toBe(2);
});

it('moves subcategories under the target and merges name clashes recursively', function () {
    $source = mergerCategory($this->user, 'Old Food');
    $target = mergerCategory($this->user, 'Food');
    $sourceCoffee = mergerCategory($this->user, 'Coffee', $source);
    $sourceTea = mergerCategory($this->user, 'Tea', $source);
    $targetCoffee = mergerCategory($this->user, 'coffee', $target);
    mergerTransactions($this->user, $this->account, $sourceCoffee, 2);
    mergerTransactions($this->user, $this->account, $targetCoffee, 1);

    $this->merger->merge($this->user, [$source->id], $target->id);

    expect(Category::query()->whereKey([$source->id, $sourceCoffee->id])->exists())->toBeFalse()
        ->and($sourceTea->fresh()->parent_id)->toBe($target->id)
        ->and(Transaction::query()->where('category_id', $targetCoffee->id)->count())->toBe(3)
        ->and(Category::query()->where('parent_id', $target->id)->count())->toBe(2);
});

it('merges several sources one at a time, deepest first', function () {
    $target = mergerCategory($this->user, 'Target');
    $outer = mergerCategory($this->user, 'Outer');
    $inner = mergerCategory($this->user, 'Inner', $outer);
    mergerTransactions($this->user, $this->account, $outer, 1);
    mergerTransactions($this->user, $this->account, $inner, 2);

    $this->merger->merge($this->user, [$outer->id, $inner->id], $target->id);

    expect(Category::query()->where('user_id', $this->user->id)->pluck('id')->all())->toBe([$target->id])
        ->and(Transaction::query()->where('category_id', $target->id)->count())->toBe(3);
});

it('replaces a merged category in the recent picks and drops duplicates', function () {
    $a = mergerCategory($this->user, 'Alpha');
    $b = mergerCategory($this->user, 'Beta');
    $c = mergerCategory($this->user, 'Gamma');
    $this->user->updatePreferences(['recentCategoryIds' => [$b->id, $c->id, $a->id]]);

    $this->merger->merge($this->user, [$b->id], $a->id);

    expect($this->user->fresh()->preferences->recentCategoryIds)->toBe([$a->id, $c->id])
        ->and($this->user->preferences->recentCategoryIds)->toBe([$a->id, $c->id]);
});

it('clears or replaces a deleted categorys references', function () {
    $doomed = mergerCategory($this->user, 'Doomed');
    $replacement = mergerCategory($this->user, 'Replacement');
    $second = mergerCategory($this->user, 'Second');
    mergerTransactions($this->user, $this->account, $doomed, 40);
    mergerTransactions($this->user, $this->account, $second, 40);

    $this->merger->deleteWithReplacement($this->user, $doomed->id);

    expect(Category::query()->whereKey($doomed->id)->exists())->toBeFalse()
        ->and(Transaction::query()->where('user_id', $this->user->id)->whereNull('category_id')->whereNull('category_source')->count())->toBe(40);

    $this->merger->deleteWithReplacement($this->user, $second->id, $replacement->id);

    expect(Transaction::query()->where('user_id', $this->user->id)->where('category_id', $replacement->id)->count())->toBe(40)
        ->and(Transaction::query()->where('user_id', $this->user->id)->whereNull('category_id')->count())->toBe(40);
});

it('clears every other reference when a category is deleted without a replacement', function () {
    $doomed = mergerCategory($this->user, 'Doomed');
    $plan = BudgetPlan::factory()->for($this->user)->create();
    $line = Budget::factory()->for($this->user)->create(['budget_plan_id' => $plan->id, 'category_id' => $doomed->id]);
    $planned = PlannedTransaction::factory()->for($this->user)->create(['category_id' => $doomed->id]);
    $payee = Payee::factory()->for($this->user)->create(['suggested_category_id' => $doomed->id, 'probabilities' => [$doomed->id => 0.6, 7 => 0.4]]);
    $tag = UserCategoryBudgetTag::query()->create(['user_id' => $this->user->id, 'category_id' => $doomed->id, 'budget_tag' => BudgetTag::Needs]);
    $run = PipelineRun::factory()->for($this->user)->create();
    $recurring = AnalysisSuggestion::factory()->create(['user_id' => $this->user->id, 'pipeline_run_id' => $run->id, 'type' => SuggestionType::RecurringTransaction, 'payload' => ['category_id' => $doomed->id]]);
    $onlyAction = AnalysisSuggestion::factory()->create(['user_id' => $this->user->id, 'pipeline_run_id' => $run->id, 'type' => SuggestionType::UserRule, 'payload' => ['actions' => [['type' => 'set_category', 'value' => (string) $doomed->id]]]]);
    $this->user->updatePreferences(['recentCategoryIds' => [$doomed->id, 5]]);

    $this->merger->deleteWithReplacement($this->user, $doomed->id);

    expect($line->fresh()->category_id)->toBeNull()
        ->and($planned->fresh()->category_id)->toBeNull()
        ->and($payee->fresh()->suggested_category_id)->toBeNull()
        ->and($payee->fresh()->probabilities)->toEqual([7 => 0.4])
        ->and(UserCategoryBudgetTag::query()->whereKey($tag->id)->exists())->toBeFalse()
        ->and($recurring->fresh()->payload['category_id'])->toBeNull()
        ->and($onlyAction->fresh()->payload['actions'])->toBe([])
        ->and($onlyAction->fresh()->status)->toBe(SuggestionStatus::Rejected)
        ->and($recurring->fresh()->status)->toBe(SuggestionStatus::Pending)
        ->and($this->user->fresh()->preferences->recentCategoryIds)->toBe([5]);
});

it('deactivates rather than widens rules that lose an action or a strict trigger on delete', function () {
    $doomed = mergerCategory($this->user, 'Doomed');
    $keep = mergerCategory($this->user, 'Keep');
    $only = mergerRule($this->user, [['field' => 'description', 'operator' => 'contains', 'value' => 'X']], [['type' => 'set_category', 'value' => (string) $doomed->id]]);
    $strict = mergerRule($this->user, [['field' => 'description', 'operator' => 'contains', 'value' => 'X'], ['field' => 'category_id', 'operator' => 'equals', 'value' => (string) $doomed->id]], [['type' => 'set_category', 'value' => (string) $keep->id]]);
    $loose = mergerRule($this->user, [['field' => 'description', 'operator' => 'contains', 'value' => 'X'], ['field' => 'category_id', 'operator' => 'equals', 'value' => (string) $doomed->id]], [['type' => 'set_category', 'value' => (string) $keep->id]], false);
    $mixed = mergerRule($this->user, [['field' => 'description', 'operator' => 'contains', 'value' => 'X']], [['type' => 'set_category', 'value' => (string) $doomed->id], ['type' => 'set_notes', 'value' => 'n']]);

    $this->merger->deleteWithReplacement($this->user, $doomed->id);

    expect($only->fresh()->is_active)->toBeFalse()
        ->and($only->fresh()->actions)->toBe([])
        ->and($strict->fresh()->is_active)->toBeFalse()
        ->and($strict->fresh()->triggers)->toHaveCount(1)
        ->and($loose->fresh()->is_active)->toBeTrue()
        ->and($loose->fresh()->triggers)->toHaveCount(1)
        ->and($mixed->fresh()->is_active)->toBeTrue()
        ->and($mixed->fresh()->actions)->toBe([['type' => 'set_notes', 'value' => 'n']]);
});

it('promotes subcategories to top level on delete and merges a clash with a top-level category', function () {
    $doomed = mergerCategory($this->user, 'Doomed');
    $plain = mergerCategory($this->user, 'Plain', $doomed);
    $clash = mergerCategory($this->user, 'Home', $doomed);
    $root = mergerCategory($this->user, 'home');
    mergerTransactions($this->user, $this->account, $clash, 2);

    $this->merger->deleteWithReplacement($this->user, $doomed->id);

    expect($plain->fresh()->parent_id)->toBeNull()
        ->and(Category::query()->whereKey($clash->id)->exists())->toBeFalse()
        ->and(Transaction::query()->where('category_id', $root->id)->count())->toBe(2);
});

it('rewrites only rows the merge is scoped to', function () {
    $a = mergerCategory($this->user, 'Alpha');
    $b = mergerCategory($this->user, 'Beta');
    $other = User::factory()->create();
    $otherRule = mergerRule($other, [['field' => 'category_id', 'operator' => 'equals', 'value' => (string) $b->id]], [['type' => 'set_category', 'value' => (string) $b->id]]);
    $otherPayee = Payee::factory()->for($other)->create(['probabilities' => [$b->id => 1.0]]);
    $otherSuggestion = AnalysisSuggestion::factory()->create(['user_id' => $other->id, 'pipeline_run_id' => PipelineRun::factory()->for($other)->create()->id, 'type' => SuggestionType::RecurringTransaction, 'payload' => ['category_id' => $b->id]]);
    mergerTransactions($this->user, $this->account, $b, 1);

    $this->merger->merge($this->user, [$b->id], $a->id);

    expect($otherRule->fresh()->actions)->toBe([['type' => 'set_category', 'value' => (string) $b->id]])
        ->and($otherRule->fresh()->triggers[0]['value'])->toBe((string) $b->id)
        ->and($otherPayee->fresh()->probabilities)->toEqual([$b->id => 1.0])
        ->and($otherSuggestion->fresh()->payload['category_id'])->toBe($b->id)
        ->and(Transaction::query()->where('user_id', $this->user->id)->where('category_id', $a->id)->count())->toBe(1);
});

it('refuses to merge or delete a category another account still references', function () {
    $a = mergerCategory($this->user, 'Alpha');
    $b = mergerCategory($this->user, 'Beta');
    $other = User::factory()->create();
    $otherTransaction = Transaction::factory()->for($other)->for(Account::factory()->for($other)->create())->create(['category_id' => $b->id]);
    $otherBudget = Budget::factory()->for($other)->create(['budget_plan_id' => BudgetPlan::factory()->for($other)->create()->id, 'category_id' => $b->id, 'limit_amount' => 5000]);
    $otherTag = UserCategoryBudgetTag::query()->create(['user_id' => $other->id, 'category_id' => $b->id, 'budget_tag' => BudgetTag::Needs]);
    mergerTransactions($this->user, $this->account, $b, 1);

    expect(fn () => $this->merger->merge($this->user, [$b->id], $a->id))->toThrow(CategoryMergeRefusedException::class)
        ->and(fn () => $this->merger->deleteWithReplacement($this->user, $b->id))->toThrow(CategoryMergeRefusedException::class);

    expect(Category::query()->whereKey($b->id)->exists())->toBeTrue()
        ->and($otherTransaction->fresh()->category_id)->toBe($b->id)
        ->and($otherBudget->fresh()->category_id)->toBe($b->id)
        ->and($otherBudget->fresh()->limit_amount)->toBe(5000)
        ->and(UserCategoryBudgetTag::query()->whereKey($otherTag->id)->exists())->toBeTrue()
        ->and(Transaction::query()->where('user_id', $this->user->id)->where('category_id', $b->id)->count())->toBe(1);
});

it('resolves a replacement that is merged away while its parent is deleted', function () {
    $doomed = mergerCategory($this->user, 'Doomed');
    $replacement = mergerCategory($this->user, 'Home', $doomed);
    $root = mergerCategory($this->user, 'home');
    mergerTransactions($this->user, $this->account, $doomed, 2);
    $rule = mergerRule($this->user, [['field' => 'description', 'operator' => 'contains', 'value' => 'X']], [['type' => 'set_category', 'value' => (string) $doomed->id]]);

    $this->merger->deleteWithReplacement($this->user, $doomed->id, $replacement->id);

    expect(Category::query()->whereKey([$doomed->id, $replacement->id])->exists())->toBeFalse()
        ->and(Transaction::query()->where('user_id', $this->user->id)->where('category_id', $root->id)->count())->toBe(2)
        ->and($rule->fresh()->actions)->toBe([['type' => 'set_category', 'value' => (string) $root->id]]);
});

it('rolls back a failed category merge', function () {
    $a = mergerCategory($this->user, 'Alpha');
    $b = mergerCategory($this->user, 'Beta');
    $child = mergerCategory($this->user, 'Child', $b);
    $plan = BudgetPlan::factory()->for($this->user)->create();
    Budget::factory()->for($this->user)->create(['budget_plan_id' => $plan->id, 'category_id' => $a->id, 'limit_amount' => 100]);
    Budget::factory()->for($this->user)->create(['budget_plan_id' => $plan->id, 'category_id' => $b->id, 'limit_amount' => 200]);
    mergerTransactions($this->user, $this->account, $b, 2);
    $rule = mergerRule($this->user, [['field' => 'description', 'operator' => 'contains', 'value' => 'X']], [['type' => 'set_category', 'value' => (string) $b->id]]);
    $this->user->updatePreferences(['recentCategoryIds' => [$b->id]]);

    DB::listen(function ($query): void {
        if (str_contains($query->sql, 'user_rules') && str_starts_with(mb_strtolower($query->sql), 'update')) {
            throw new RuntimeException('simulated failure');
        }
    });

    expect(fn () => $this->merger->merge($this->user, [$b->id], $a->id))->toThrow(RuntimeException::class, 'simulated failure');

    expect(Category::query()->whereKey($b->id)->exists())->toBeTrue()
        ->and($child->fresh()->parent_id)->toBe($b->id)
        ->and(Transaction::query()->where('category_id', $b->id)->count())->toBe(2)
        ->and(Budget::query()->where('category_id', $a->id)->value('limit_amount'))->toBe(100)
        ->and(Budget::query()->where('category_id', $b->id)->value('limit_amount'))->toBe(200)
        ->and($rule->fresh()->actions)->toBe([['type' => 'set_category', 'value' => (string) $b->id]])
        ->and($this->user->fresh()->preferences->recentCategoryIds)->toBe([$b->id]);
});

it('refuses creates while the category lock is on but not while it is off', function () {
    $creator = app(CategoryCreator::class);

    expect($creator->create($this->user, 'Open', null)->user_id)->toBe($this->user->id);

    $this->user->updatePreferences(['categoriesLocked' => true]);

    expect(fn () => $creator->create($this->user, 'Closed'))->toThrow(CategoriesLockedException::class)
        ->and(Category::query()->where('user_id', $this->user->id)->pluck('name')->all())->toBe(['Open']);
});

it('does not let a create attach to another user\'s parent', function () {
    $stranger = mergerCategory(User::factory()->create(), 'Theirs');

    expect(fn () => app(CategoryCreator::class)->create($this->user, 'Mine', $stranger->id))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('gives a new subcategory its parent\'s colour', function () {
    $parent = Category::factory()->for($this->user)->create(['name' => 'Food', 'color' => '#abcdef']);

    $child = app(CategoryCreator::class)->create($this->user, 'Coffee', $parent->id);
    $root = app(CategoryCreator::class)->create($this->user, 'Plain');

    expect($child->fresh()->color)->toBe('#abcdef')
        ->and($root->fresh()->color)->toBeNull();
});

it('keeps the target\'s budget tag when the source had an override', function () {
    $source = Category::factory()->for($this->user)->create(['name' => 'S', 'budget_tag' => BudgetTag::Wants]);
    $target = Category::factory()->for($this->user)->create(['name' => 'T', 'budget_tag' => BudgetTag::Wants]);
    UserCategoryBudgetTag::query()->create(['user_id' => $this->user->id, 'category_id' => $source->id, 'budget_tag' => BudgetTag::Needs]);

    $this->merger->merge($this->user, [$source->id], $target->id);

    expect(UserCategoryBudgetTag::query()->where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('leaves a strict rule untouched when delete only affects a not-equals category trigger', function (array $extra) {
    $doomed = mergerCategory($this->user, 'Doomed');
    $keep = mergerCategory($this->user, 'Keep');
    $triggers = [...$extra, ['field' => 'category_id', 'operator' => 'is_not', 'value' => (string) $doomed->id]];
    $rule = mergerRule($this->user, $triggers, [['type' => 'set_category', 'value' => (string) $keep->id]]);

    $this->merger->deleteWithReplacement($this->user, $doomed->id);

    expect($rule->fresh()->is_active)->toBeTrue()
        ->and($rule->fresh()->triggers)->toBe($triggers);
})->with([
    'alongside another trigger' => [[['field' => 'description', 'operator' => 'contains', 'value' => 'X']]],
    'as the only trigger' => [[]],
]);

it('replaces a deleted category with its replacement in the recent picks', function () {
    $doomed = mergerCategory($this->user, 'Doomed');
    $replacement = mergerCategory($this->user, 'Replacement');
    $this->user->updatePreferences(['recentCategoryIds' => [$doomed->id, $replacement->id]]);

    $this->merger->deleteWithReplacement($this->user, $doomed->id, $replacement->id);

    expect($this->user->fresh()->preferences->recentCategoryIds)->toBe([$replacement->id]);
});
