<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BudgetTag;
use App\Enums\CategorySource;
use App\Enums\PayeeStatus;
use App\Enums\TransactionDirection;
use App\Livewire\TransactionList;
use App\Models\Account;
use App\Models\Category;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->groceries = Category::factory()->create(['name' => 'Groceries', 'budget_tag' => BudgetTag::Needs]);
    $this->software = Category::factory()->create(['name' => 'Software & Online Services', 'budget_tag' => BudgetTag::Needs]);
    $this->personal = Category::factory()->create(['name' => 'Personal & Shopping', 'budget_tag' => BudgetTag::Wants]);
});

function clusterRows(User $user, Account $account, string $description, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        Transaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'description' => $description,
            'merchant_name' => null,
            'clean_description' => null,
            'category_id' => null,
            'amount' => 2500,
            'direction' => TransactionDirection::Debit,
            'post_date' => CarbonImmutable::parse('2026-06-10')->subDays($i),
            'transfer_pair_id' => null,
        ]);
    }
}

function clusterPayee(User $user, string $merchantKey, ?Category $suggested): Payee
{
    return Payee::factory()->create([
        'user_id' => $user->id,
        'merchant_key' => $merchantKey,
        'merchant_name' => $merchantKey,
        'suggested_category_id' => $suggested?->id,
        'confidence' => 0.5,
        'top_to_second' => 1.2,
    ]);
}

test('selecting a cluster prefills the suggested category and shows its tag', function () {
    clusterRows($this->user, $this->account, 'WOOLWORTHS 1234', 3);
    $key = Transaction::query()->where('user_id', $this->user->id)->value('merchant_key');
    clusterPayee($this->user, $key, $this->groceries);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->set('groupMode', 'merchant')
        ->call('selectCluster', $key)
        ->assertSet('bulkCategoryId', (string) $this->groceries->id)
        ->assertSeeHtml('data-testid="cluster-suggestion"')
        ->assertSeeHtml('data-testid="cluster-tag"');
});

test('applying the suggestion to the whole cluster confirms the payee and keeps the choice for later rows', function () {
    clusterRows($this->user, $this->account, 'WOOLWORTHS 1234', 3);
    $key = Transaction::query()->where('user_id', $this->user->id)->value('merchant_key');
    $payee = clusterPayee($this->user, $key, $this->groceries);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->set('groupMode', 'merchant')
        ->call('selectCluster', $key)
        ->call('applyCategoryToSelection');

    $payee->refresh();

    expect($payee->status)->toBe(PayeeStatus::Confirmed)
        ->and($payee->confirmed_category_id)->toBe($this->groceries->id)
        ->and($payee->user_rule_id)->not->toBeNull()
        ->and(Transaction::query()->where('merchant_key', $key)->where('category_source', CategorySource::Manual)->count())->toBe(3);
});

test('applying to hand-picked rows of a cluster does not confirm the payee', function () {
    clusterRows($this->user, $this->account, 'WOOLWORTHS 1234', 3);
    $key = Transaction::query()->where('user_id', $this->user->id)->value('merchant_key');
    $payee = clusterPayee($this->user, $key, $this->groceries);
    $first = Transaction::query()->where('merchant_key', $key)->orderBy('id')->first();

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$first->id => true])
        ->set('bulkCategoryId', (string) $this->groceries->id)
        ->call('applyCategoryToSelection');

    expect($payee->fresh()->status)->toBe(PayeeStatus::Pending)
        ->and(Transaction::query()->where('merchant_key', $key)->whereNotNull('category_id')->count())->toBe(1);
});

test('choosing work for an ambiguous cluster narrows the picker to work categories', function () {
    clusterRows($this->user, $this->account, 'APPLE.COM/BILL', 3);
    $key = Transaction::query()->where('user_id', $this->user->id)->value('merchant_key');
    clusterPayee($this->user, $key, null)->update(['suggested_category_id' => $this->personal->id]);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->set('groupMode', 'merchant')
        ->call('selectCluster', $key)
        ->assertSeeHtml('data-testid="cluster-intent"')
        ->set('clusterIntent', 'work');

    expect($component->viewData('bulkCategories')->pluck('id')->all())->toBe([$this->software->id])
        ->and($component->get('bulkCategoryId'))->toBe((string) $this->software->id);
});

test('an already categorised cluster gets no prefill', function () {
    clusterRows($this->user, $this->account, 'WOOLWORTHS 1234', 2);
    $key = Transaction::query()->where('user_id', $this->user->id)->value('merchant_key');
    clusterPayee($this->user, $key, $this->groceries);
    Transaction::query()->where('merchant_key', $key)->update(['category_id' => $this->personal->id]);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('groupMode', 'merchant')
        ->call('selectCluster', $key)
        ->assertSet('bulkCategoryId', '')
        ->assertDontSeeHtml('data-testid="cluster-suggestion"');
});
