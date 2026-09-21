<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\TransactionDirection;
use App\Livewire\TransactionList;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Services\CategoryRuleGenerator;
use Carbon\CarbonImmutable;
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

it('defaults the match value from the merchant key in cluster mode', function () {
    ruleTxn($this->user, $this->account, 'PAYPAL *STEAM 4829');
    ruleTxn($this->user, $this->account, 'PAYPAL *STEAM 5561');

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->set('groupMode', 'merchant')
        ->call('selectCluster', 'PAYPAL STEAM')
        ->call('openRulePanel');

    expect($component->get('ruleMatchValue'))->toBe('PAYPAL STEAM');
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
