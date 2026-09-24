<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\TransactionDirection;
use App\Livewire\TransactionList;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Services\CategoryRuleGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->category = Category::factory()->create(['is_hidden' => false]);
});

function ruleTxn(User $user, Account $account, string $description, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => $description,
        'merchant_name' => null,
        'clean_description' => null,
        'category_id' => null,
        'amount' => 1899,
        'direction' => TransactionDirection::Debit,
        'post_date' => CarbonImmutable::parse('2026-06-10'),
        'transfer_pair_id' => null,
    ], $overrides));
}

it('creates exactly one rule from a multi-row selection', function () {
    $a = ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    $b = ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    $c = ruleTxn($this->user, $this->account, 'NETFLIX.COM');

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$a->id => true, $b->id => true, $c->id => true])
        ->set('bulkCategoryId', (string) $this->category->id)
        ->set('ruleMatchValue', 'NETFLIX')
        ->call('createRuleFromSelection');

    expect(UserRule::where('user_id', $this->user->id)->count())->toBe(1);
});

it('applies the rule to matching rows outside the selection', function () {
    $selected = ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    $notSelected = ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    $unrelated = ruleTxn($this->user, $this->account, 'SPOTIFY AB');

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$selected->id => true])
        ->set('bulkCategoryId', (string) $this->category->id)
        ->set('ruleMatchValue', 'NETFLIX')
        ->call('createRuleFromSelection');

    expect($selected->fresh()->category_id)->toBe($this->category->id)
        ->and($notSelected->fresh()->category_id)->toBe($this->category->id)
        ->and($unrelated->fresh()->category_id)->toBeNull();
});

it('the preview count equals the number actually changed', function () {
    // A preview that disagrees with the result is worse than no preview, so
    // this pins the two together rather than testing the preview in isolation.
    $selected = ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    ruleTxn($this->user, $this->account, 'SPOTIFY AB');

    $generator = app(CategoryRuleGenerator::class);
    $preview = $generator->preview($selected, $this->category->id, 'NETFLIX', [$selected->id]);

    $generator->generateAndApply($selected, $this->category->id, 'NETFLIX');

    $actuallyChanged = Transaction::where('user_id', $this->user->id)
        ->where('category_id', $this->category->id)
        ->count();

    expect($preview->wouldChange)->toBe(3)
        ->and($actuallyChanged)->toBe($preview->wouldChange);
});

it('counts a feed-sourced row already in the target category as changed', function () {
    // The executor restamps it Feed → Rule, so the sweep writes it; the
    // preview must count that write or the notice under-reports.
    $selected = ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    $feed = ruleTxn($this->user, $this->account, 'NETFLIX.COM', [
        'category_id' => $this->category->id,
        'category_source' => CategorySource::Feed,
    ]);

    $generator = app(CategoryRuleGenerator::class);
    $preview = $generator->preview($selected, $this->category->id, 'NETFLIX', [$selected->id]);

    $generator->generateAndApply($selected, $this->category->id, 'NETFLIX');

    expect($preview->wouldChange)->toBe(2)
        ->and($feed->fresh()->category_source)->toBe(CategorySource::Rule);
});

it('reports rows protected by a manual category and leaves them untouched', function () {
    $otherCategory = Category::factory()->create(['is_hidden' => false]);

    $selected = ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    $manual = ruleTxn($this->user, $this->account, 'NETFLIX.COM', [
        'category_id' => $otherCategory->id,
        'category_source' => CategorySource::Manual,
    ]);

    $generator = app(CategoryRuleGenerator::class);
    $preview = $generator->preview($selected, $this->category->id, 'NETFLIX', [$selected->id]);

    expect($preview->protectedByManual)->toBe(1);

    $generator->generateAndApply($selected, $this->category->id, 'NETFLIX');

    expect($manual->fresh()->category_id)->toBe($otherCategory->id)
        ->and($selected->fresh()->category_id)->toBe($this->category->id);
});

it('separates matches inside the selection from those beyond it', function () {
    $selected = ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    ruleTxn($this->user, $this->account, 'NETFLIX.COM');

    $preview = app(CategoryRuleGenerator::class)
        ->preview($selected, $this->category->id, 'NETFLIX', [$selected->id]);

    expect($preview->inSelection)->toBe(1)
        ->and($preview->beyondSelection)->toBe(2)
        ->and($preview->totalMatches())->toBe(3);
});

it('lists the categories that matching rows outside the selection already hold', function () {
    $other = Category::factory()->create(['is_hidden' => false, 'name' => 'Streaming']);

    $selected = ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    ruleTxn($this->user, $this->account, 'NETFLIX.COM', [
        'category_id' => $other->id,
        'category_source' => CategorySource::Rule,
    ]);

    $preview = app(CategoryRuleGenerator::class)
        ->preview($selected, $this->category->id, 'NETFLIX', [$selected->id]);

    expect($preview->existingCategories)->toBe(['Streaming' => 1]);
});

it('honours an edited match value rather than the suggested one', function () {
    $selected = ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    $spotify = ruleTxn($this->user, $this->account, 'SPOTIFY AB');

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$selected->id => true])
        ->set('bulkCategoryId', (string) $this->category->id)
        // Deliberately widened to something that matches nothing, proving the
        // edited value is what the rule carries.
        ->set('ruleMatchValue', 'NOTHINGMATCHESTHIS')
        ->call('createRuleFromSelection');

    expect($selected->fresh()->category_id)->toBeNull()
        ->and($spotify->fresh()->category_id)->toBeNull()
        ->and(UserRule::where('user_id', $this->user->id)->first()->triggers[0]['value'])
        ->toBe('NOTHINGMATCHESTHIS');
});

it('defaults a cluster rule to a value that matches the cluster it was built from', function () {
    // The cluster key is a normalised signature ('PAYPAL STEAM'), not a
    // substring of the raw descriptions, so it cannot be the `description
    // contains` default: a rule carrying it would match none of these rows.
    $a = ruleTxn($this->user, $this->account, 'PAYPAL *STEAM 4829');
    $b = ruleTxn($this->user, $this->account, 'PAYPAL *STEAM 5561');

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->set('groupMode', 'merchant')
        ->call('selectCluster', 'PAYPAL STEAM')
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('openRulePanel')
        ->call('createRuleFromSelection');

    expect($a->fresh()->category_id)->toBe($this->category->id)
        ->and($b->fresh()->category_id)->toBe($this->category->id);
});

it('counts a whole collapsed cluster as inside the selection', function () {
    // 30 rows: more than a page, and the cluster is never expanded, so not
    // one of them is ticked on screen. They are all still the selection.
    foreach (range(1, 30) as $i) {
        ruleTxn($this->user, $this->account, 'PAYPAL *STEAM '.(4000 + $i));
    }

    $preview = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->set('groupMode', 'merchant')
        ->call('selectCluster', 'PAYPAL STEAM')
        ->set('bulkCategoryId', (string) $this->category->id)
        ->set('ruleMatchValue', 'STEAM')
        ->call('openRulePanel')
        ->viewData('rulePreview');

    expect($preview->inSelection)->toBe(30)
        ->and($preview->beyondSelection)->toBe(0);
});

it('re-derives the rule from a new selection instead of reusing the last one', function () {
    $netflix = ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    $spotify = ruleTxn($this->user, $this->account, 'SPOTIFY AB');

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected.'.$netflix->id, true)
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('openRulePanel');

    expect($component->get('ruleMatchValue'))->toContain('NETFLIX');

    // Clearing hides the bar; the panel must not reappear pre-filled with a
    // trigger built from rows that are no longer selected.
    $component->call('clearSelection')
        ->set('selected.'.$spotify->id, true);

    expect($component->get('rulePanelOpen'))->toBeFalse()
        ->and($component->get('ruleMatchValue'))->toBe('');

    $component->set('bulkCategoryId', (string) $this->category->id)
        ->call('openRulePanel')
        ->call('createRuleFromSelection');

    expect($spotify->fresh()->category_id)->toBe($this->category->id)
        ->and($netflix->fresh()->category_id)->toBeNull();
});

it('closes the panel when a tick changes the selection it previewed', function () {
    $a = ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    $b = ruleTxn($this->user, $this->account, 'NETFLIX.COM');

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected.'.$a->id, true)
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('openRulePanel')
        ->set('selected.'.$b->id, true);

    expect($component->get('rulePanelOpen'))->toBeFalse();
});

it('leaves a planned sibling the rule does not match untouched', function () {
    $other = Category::factory()->create(['is_hidden' => false]);
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->create();

    $selected = ruleTxn($this->user, $this->account, 'NETFLIX.COM', ['planned_transaction_id' => $planned->id]);
    $sibling = ruleTxn($this->user, $this->account, 'SUBSCRIPTION REFUND', [
        'planned_transaction_id' => $planned->id,
        'category_id' => $other->id,
        'category_source' => CategorySource::Manual,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected.'.$selected->id, true)
        ->set('bulkCategoryId', (string) $this->category->id)
        ->set('ruleMatchValue', 'NETFLIX')
        ->call('openRulePanel')
        ->call('createRuleFromSelection');

    // The notice repeats the preview, so it is only true if the sweep writes
    // exactly the rows the preview counted.
    expect($selected->fresh()->category_id)->toBe($this->category->id)
        ->and($sibling->fresh()->category_id)->toBe($other->id)
        ->and($sibling->fresh()->category_source)->toBe(CategorySource::Manual)
        ->and($component->get('bulkNotice'))->toBe('Rule created. 1 transaction categorised.');
});

it('shows no preview numbers until a category is chosen', function () {
    $txn = ruleTxn($this->user, $this->account, 'NETFLIX.COM');

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected.'.$txn->id, true)
        ->call('openRulePanel')
        ->assertViewHas('rulePreview', null)
        ->assertSeeHtml('data-testid="rule-preview-empty"');
});

it('updates the preview when the match value or category changes', function () {
    $selected = ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    ruleTxn($this->user, $this->account, 'SPOTIFY AB');

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected.'.$selected->id, true)
        ->call('openRulePanel')
        ->set('bulkCategoryId', (string) $this->category->id)
        ->set('ruleMatchValue', 'NETFLIX');

    expect($component->viewData('rulePreview')->totalMatches())->toBe(2);

    $component->set('ruleMatchValue', 'SPOTIFY');

    expect($component->viewData('rulePreview')->totalMatches())->toBe(1);
});

it('keeps same-named categories in different branches apart', function () {
    $office = Category::factory()->create(['is_hidden' => false, 'name' => 'Office']);
    $personal = Category::factory()->create(['is_hidden' => false, 'name' => 'Personal']);
    $officeSub = Category::factory()->create(['is_hidden' => false, 'name' => 'Subscription', 'parent_id' => $office->id]);
    $personalSub = Category::factory()->create(['is_hidden' => false, 'name' => 'Subscription', 'parent_id' => $personal->id]);

    $selected = ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    ruleTxn($this->user, $this->account, 'NETFLIX.COM', ['category_id' => $officeSub->id, 'category_source' => CategorySource::Rule]);
    ruleTxn($this->user, $this->account, 'NETFLIX.COM', ['category_id' => $personalSub->id, 'category_source' => CategorySource::Rule]);

    $preview = app(CategoryRuleGenerator::class)
        ->preview($selected, $this->category->id, 'NETFLIX', [$selected->id]);

    expect($preview->existingCategories)->toEqualCanonicalizing([
        'Office / Subscription' => 1,
        'Personal / Subscription' => 1,
    ]);
});

it('treats a categorised row with no recorded source as manual, as the sweep does', function () {
    $other = Category::factory()->create(['is_hidden' => false]);
    $selected = ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    $legacy = ruleTxn($this->user, $this->account, 'NETFLIX.COM', ['category_id' => $other->id]);

    // saving() defaults the source, so only a raw write reproduces a row that
    // predates provenance.
    DB::table('transactions')->where('id', $legacy->id)->update(['category_source' => null]);

    $generator = app(CategoryRuleGenerator::class);
    $preview = $generator->preview($selected, $this->category->id, 'NETFLIX', [$selected->id]);
    $generator->generateAndApply($selected, $this->category->id, 'NETFLIX');

    expect($preview->protectedByManual)->toBe(1)
        ->and($preview->wouldChange)->toBe(1)
        ->and($legacy->fresh()->category_id)->toBe($other->id);
});

it('refuses to create a rule without a category or a match value', function () {
    $txn = ruleTxn($this->user, $this->account, 'NETFLIX.COM');

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$txn->id => true])
        ->set('ruleMatchValue', 'NETFLIX')
        ->call('createRuleFromSelection');

    expect($component->get('bulkError'))->toBe('Choose a category first.')
        ->and(UserRule::count())->toBe(0);

    $component->set('bulkCategoryId', (string) $this->category->id)
        ->set('ruleMatchValue', '   ')
        ->call('createRuleFromSelection');

    expect($component->get('bulkError'))->toBe('Give the rule something to match on.')
        ->and(UserRule::count())->toBe(0);
});

it('never builds a rule from a transfer', function () {
    $other = ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    $transfer = ruleTxn($this->user, $this->account, 'TRANSFER OUT', ['transfer_pair_id' => $other->id]);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$transfer->id => true])
        ->set('bulkCategoryId', (string) $this->category->id)
        ->set('ruleMatchValue', 'TRANSFER')
        ->call('createRuleFromSelection');

    // The transfer is not eligible, so the selection resolves to nothing.
    expect($component->get('bulkError'))->toBe('Nothing is selected.')
        ->and(UserRule::count())->toBe(0)
        ->and($transfer->fresh()->category_id)->toBeNull();
});

it('stamps rule-applied rows as rule, not manual', function () {
    $selected = ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    $beyond = ruleTxn($this->user, $this->account, 'NETFLIX.COM');

    app(CategoryRuleGenerator::class)
        ->generateAndApply($selected, $this->category->id, 'NETFLIX');

    expect($beyond->fresh()->category_source)->toBe(CategorySource::Rule);
});

it('closes the panel and clears the selection after creating a rule', function () {
    $txn = ruleTxn($this->user, $this->account, 'NETFLIX.COM');

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$txn->id => true])
        ->set('bulkCategoryId', (string) $this->category->id)
        ->set('ruleMatchValue', 'NETFLIX')
        ->call('openRulePanel')
        ->call('createRuleFromSelection');

    expect($component->get('rulePanelOpen'))->toBeFalse()
        ->and($component->get('selected'))->toBe([])
        ->and($component->get('bulkNotice'))->toContain('Rule created.');
});

it('surfaces the live preview in the panel view data', function () {
    $selected = ruleTxn($this->user, $this->account, 'NETFLIX.COM');
    ruleTxn($this->user, $this->account, 'NETFLIX.COM');

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$selected->id => true])
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('openRulePanel');

    $preview = $component->viewData('rulePreview');

    expect($preview)->not->toBeNull()
        ->and($preview->beyondSelection)->toBe(1);
});
