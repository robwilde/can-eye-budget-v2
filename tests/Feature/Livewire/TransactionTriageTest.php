<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Enums\TransactionDirection;
use App\Livewire\TransactionList;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->utilities = Category::factory()->for($this->user)->create(['name' => 'Utilities', 'is_hidden' => false]);
});

function triageRows(User $user, Account $account, string $description, int $count, array $overrides = []): array
{
    $rows = [];

    foreach (range(1, $count) as $i) {
        $rows[] = Transaction::factory()->create(array_merge([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'description' => $description,
            'merchant_name' => null,
            'clean_description' => null,
            'category_id' => null,
            'amount' => 1000 + $i,
            'direction' => TransactionDirection::Debit,
            'post_date' => CarbonImmutable::parse('2026-06-10'),
            'transfer_pair_id' => null,
        ], $overrides));
    }

    return $rows;
}

function triageList(User $user): Testable
{
    return Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->set('groupMode', 'merchant');
}

function triageAssign($component, string $key, Category $category, bool $createRule)
{
    return $component
        ->call('selectCluster', $key)
        ->set('bulkCategoryId', (string) $category->id)
        ->set('createRule', $createRule)
        ->call('applyCategoryToSelection');
}

it('categorises a group and creates one active rule', function () {
    $rows = triageRows($this->user, $this->account, 'ACME PTY LTD', 10);
    $key = $rows[0]->fresh()->merchant_key;

    triageAssign(triageList($this->user), $key, $this->utilities, true);

    $rule = UserRule::query()->where('user_id', $this->user->id)->sole();

    expect(Transaction::query()->where('category_id', $this->utilities->id)->count())->toBe(10)
        ->and(Transaction::query()->whereNull('category_id')->count())->toBe(0)
        ->and($rule->is_active)->toBeTrue()
        ->and($rule->is_auto_apply)->toBeTrue()
        ->and($rule->name)->toBe($key)
        ->and($rule->triggers)->toBe([['field' => RuleTriggerField::MerchantKey->value, 'operator' => RuleTriggerOperator::Contains->value, 'value' => $key]])
        ->and($rule->actions)->toBe([['type' => RuleActionType::SetCategory->value, 'value' => (string) $this->utilities->id]]);
});

it('adds the direction condition when a direction is chosen', function () {
    $rows = triageRows($this->user, $this->account, 'ACME PTY LTD', 2, ['direction' => TransactionDirection::Credit]);
    $key = $rows[0]->fresh()->merchant_key;

    triageAssign(triageList($this->user)->set('direction', 'incoming'), $key, $this->utilities, true);

    expect(UserRule::query()->sole()->triggers[1])->toBe(['field' => RuleTriggerField::Direction->value, 'operator' => RuleTriggerOperator::Equals->value, 'value' => 'credit']);
});

it('creates no rule when the box is not ticked', function () {
    $rows = triageRows($this->user, $this->account, 'ACME PTY LTD', 2);

    triageAssign(triageList($this->user), $rows[0]->fresh()->merchant_key, $this->utilities, false);

    expect(UserRule::query()->count())->toBe(0)
        ->and(Transaction::query()->where('category_id', $this->utilities->id)->count())->toBe(2);
});

it('categorises the group even when the rule already exists', function () {
    $first = triageRows($this->user, $this->account, 'ACME PTY LTD', 3);
    $key = $first[0]->fresh()->merchant_key;
    $component = triageList($this->user);
    triageAssign($component, $key, $this->utilities, true);

    $more = triageRows($this->user, $this->account, 'ACME PTY LTD', 2, ['post_date' => CarbonImmutable::parse('2026-06-12')]);

    triageAssign($component, $key, $this->utilities, true)
        ->assertSet('triageAlert', 'Transactions categorized, but the rule could not be created (it may already exist).')
        ->assertSee('Transactions categorized, but the rule could not be created');

    expect(UserRule::query()->count())->toBe(1)
        ->and(Transaction::query()->whereIn('id', array_map(fn (Transaction $t): int => $t->id, $more))->pluck('category_id')->unique()->all())->toBe([$this->utilities->id]);
});

it('never offers split parent transactions in triage', function () {
    $parent = triageRows($this->user, $this->account, 'GLOBEX SPLIT', 1, ['excluded' => true])[0];
    triageRows($this->user, $this->account, 'GLOBEX SPLIT', 1, ['split_parent_id' => $parent->id, 'category_id' => $this->utilities->id, 'category_source' => CategorySource::Manual]);
    triageRows($this->user, $this->account, 'ACME PTY LTD', 1);

    $component = triageList($this->user);
    $keys = collect($component->viewData('clusters')->items())->pluck('merchant_key')->all();

    expect($keys)->toBe([Transaction::query()->where('description', 'ACME PTY LTD')->value('merchant_key')])
        ->and($component->viewData('triageRemaining')['rows'])->toBe(1);
});

it('returns to the list when nothing is left to categorise', function () {
    $rows = triageRows($this->user, $this->account, 'ACME PTY LTD', 2);

    $component = Livewire::actingAs($this->user)->test(TransactionList::class)->call('startTriage');

    $component->assertSet('expandedKey', $rows[0]->fresh()->merchant_key)
        ->set('bulkCategoryId', (string) $this->utilities->id)
        ->call('applyCategoryToSelection')
        ->assertSet('categorised', 'all')
        ->assertSet('groupMode', 'date')
        ->assertSet('triage', false)
        ->assertSet('bulkNotice', 'All transactions are categorized')
        ->assertSee('All transactions are categorized');
});

it('advances to the next group after each assignment', function () {
    $big = triageRows($this->user, $this->account, 'ACME PTY LTD', 3);
    $small = triageRows($this->user, $this->account, 'GLOBEX CORP', 1);

    $component = Livewire::actingAs($this->user)->test(TransactionList::class)->call('startTriage');

    $component->assertSet('expandedKey', $big[0]->fresh()->merchant_key)
        ->set('bulkCategoryId', (string) $this->utilities->id)
        ->call('applyCategoryToSelection')
        ->assertSet('triage', true)
        ->assertSet('expandedKey', $small[0]->fresh()->merchant_key);
});

it('skips a group without categorising it', function () {
    $big = triageRows($this->user, $this->account, 'ACME PTY LTD', 3);
    $small = triageRows($this->user, $this->account, 'GLOBEX CORP', 1);

    Livewire::actingAs($this->user)->test(TransactionList::class)
        ->call('startTriage')
        ->call('nextCluster')
        ->assertSet('expandedKey', $small[0]->fresh()->merchant_key);

    expect(Transaction::query()->whereNull('category_id')->count())->toBe(4)
        ->and($big)->toHaveCount(3);
});

it('lifts the direction filter when nothing is left in that direction', function () {
    $credit = triageRows($this->user, $this->account, 'ACME PTY LTD', 1, ['direction' => TransactionDirection::Credit]);
    $debit = triageRows($this->user, $this->account, 'GLOBEX CORP', 1);

    Livewire::actingAs($this->user)->test(TransactionList::class)
        ->set('direction', 'incoming')
        ->call('startTriage')
        ->assertSet('expandedKey', $credit[0]->fresh()->merchant_key)
        ->set('bulkCategoryId', (string) $this->utilities->id)
        ->call('applyCategoryToSelection')
        ->assertSet('triage', true)
        ->assertSet('direction', 'all')
        ->assertSet('expandedKey', $debit[0]->fresh()->merchant_key);
});

it('counts only the current user uncategorised transactions', function () {
    triageRows($this->user, $this->account, 'ACME PTY LTD', 2);
    $other = User::factory()->create();
    triageRows($other, Account::factory()->for($other)->create(), 'ACME PTY LTD', 5);

    $remaining = triageList($this->user)->viewData('triageRemaining');

    expect($remaining['rows'])->toBe(2)
        ->and($remaining['total'])->toBe(1001 + 1002);
});

it('updates the remaining count live as the direction filter changes', function () {
    triageRows($this->user, $this->account, 'ACME PTY LTD', 2);
    triageRows($this->user, $this->account, 'REFUND CO', 1, ['direction' => TransactionDirection::Credit]);

    $component = triageList($this->user);

    expect($component->viewData('triageRemaining')['rows'])->toBe(3)
        ->and($component->set('direction', 'incoming')->viewData('triageRemaining')['rows'])->toBe(1)
        ->and($component->set('direction', 'outgoing')->viewData('triageRemaining')['rows'])->toBe(2);
});

it('audits applying a category to a triage group', function () {
    $rows = triageRows($this->user, $this->account, 'ACME PTY LTD', 2);

    triageAssign(triageList($this->user), $rows[0]->fresh()->merchant_key, $this->utilities, true);

    $event = AuditEvent::query()->where('action', 'livewire.transaction-list.applyCategoryToSelection')->sole();

    expect($event->user_id)->toBe($this->user->id);
});
