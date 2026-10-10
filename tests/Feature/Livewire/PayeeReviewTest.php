<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BudgetTag;
use App\Enums\CategorySource;
use App\Enums\PayeeStatus;
use App\Enums\TransactionDirection;
use App\Livewire\PayeeReview;
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
    $this->aiApps = Category::factory()->withParent($this->software)->create(['name' => 'AI Apps']);
    $this->personal = Category::factory()->create(['name' => 'Personal & Shopping', 'budget_tag' => BudgetTag::Wants]);
    $this->clothes = Category::factory()->withParent($this->personal)->create(['name' => 'Clothes']);
});

function reviewPayee(User $user, Account $account, string $name, int $amount, ?Category $suggested, int $count = 3, array $payeeAttributes = []): Payee
{
    $key = null;

    for ($i = 0; $i < $count; $i++) {
        $transaction = Transaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'description' => $name,
            'merchant_name' => $name,
            'category_id' => null,
            'amount' => $amount,
            'direction' => TransactionDirection::Debit,
            'post_date' => CarbonImmutable::parse('2026-06-10')->subDays($i * 7),
            'transfer_pair_id' => null,
        ]);

        $key = $transaction->merchant_key;
    }

    return Payee::factory()->create(array_merge([
        'user_id' => $user->id,
        'merchant_key' => $key,
        'merchant_name' => $name,
        'suggested_category_id' => $suggested?->id,
        'confidence' => 0.5,
        'top_to_second' => 1.2,
    ], $payeeAttributes));
}

test('payees are listed biggest monthly spend first', function () {
    reviewPayee($this->user, $this->account, 'SMALL BAKERY', 500, $this->groceries);
    reviewPayee($this->user, $this->account, 'HUGE SUPERMARKET', 40000, $this->groceries);
    reviewPayee($this->user, $this->account, 'MEDIUM BUTCHER', 6000, $this->groceries);

    Livewire::actingAs($this->user)
        ->test(PayeeReview::class)
        ->assertSeeInOrder(['HUGE SUPERMARKET', 'MEDIUM BUTCHER', 'SMALL BAKERY']);
});

test('the suggested category is preselected with its tag shown', function () {
    $payee = reviewPayee($this->user, $this->account, 'FRESH GROCER', 3000, $this->groceries);

    Livewire::actingAs($this->user)
        ->test(PayeeReview::class)
        ->assertSet('categoryChoices.'.$payee->id, $this->groceries->id)
        ->assertSeeHtml('data-test="payee-tag"')
        ->assertSee('Needs');
});

test('skipping dispatches the finished event and leaves every payee pending', function () {
    $payee = reviewPayee($this->user, $this->account, 'FRESH GROCER', 3000, $this->groceries);

    Livewire::actingAs($this->user)
        ->test(PayeeReview::class, ['onboarding' => true])
        ->assertSee('Skip for now')
        ->call('finish')
        ->assertDispatched('payee-review-finished');

    expect($payee->fresh()->status)->toBe(PayeeStatus::Pending)
        ->and(Transaction::query()->where('user_id', $this->user->id)->whereNotNull('category_id')->count())->toBe(0);
});

test('accept all suggested categorises every suggested payee and skips the ones without a suggestion', function () {
    $suggested = reviewPayee($this->user, $this->account, 'FRESH GROCER', 3000, $this->groceries);
    $unsuggested = reviewPayee($this->user, $this->account, 'MYSTERY SHOP', 2000, null);

    Livewire::actingAs($this->user)
        ->test(PayeeReview::class)
        ->call('acceptAll');

    expect($suggested->fresh()->status)->toBe(PayeeStatus::Confirmed)
        ->and($unsuggested->fresh()->status)->toBe(PayeeStatus::Pending)
        ->and(Transaction::query()->where('merchant_key', $suggested->merchant_key)->where('category_id', $this->groceries->id)->where('category_source', CategorySource::Manual)->count())->toBe(3)
        ->and(Transaction::query()->where('merchant_key', $unsuggested->merchant_key)->whereNotNull('category_id')->count())->toBe(0);
});

test('accept uses the category the user picked and the tag override', function () {
    $payee = reviewPayee($this->user, $this->account, 'FRESH GROCER', 3000, $this->groceries);

    Livewire::actingAs($this->user)
        ->test(PayeeReview::class)
        ->set('categoryChoices.'.$payee->id, $this->clothes->id)
        ->set('tagChoices.'.$payee->id, BudgetTag::Savings->value)
        ->call('accept', $payee->id);

    $payee->refresh();

    expect($payee->status)->toBe(PayeeStatus::Confirmed)
        ->and($payee->confirmed_category_id)->toBe($this->clothes->id)
        ->and($payee->budget_tag)->toBe(BudgetTag::Savings)
        ->and(Transaction::query()->where('merchant_key', $payee->merchant_key)->where('category_id', $this->clothes->id)->count())->toBe(3);
});

test('accepting re-categorises every transaction of that merchant but nothing of another user', function () {
    $payee = reviewPayee($this->user, $this->account, 'FRESH GROCER', 3000, $this->groceries);

    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->create();
    $otherTransaction = Transaction::factory()->create([
        'user_id' => $other->id,
        'account_id' => $otherAccount->id,
        'description' => 'FRESH GROCER',
        'merchant_name' => 'FRESH GROCER',
        'category_id' => null,
        'amount' => 3000,
        'direction' => TransactionDirection::Debit,
        'post_date' => CarbonImmutable::parse('2026-06-10'),
        'transfer_pair_id' => null,
    ]);

    Livewire::actingAs($this->user)
        ->test(PayeeReview::class)
        ->call('accept', $payee->id);

    expect(Transaction::query()->where('user_id', $this->user->id)->where('merchant_key', $payee->merchant_key)->where('category_id', $this->groceries->id)->count())->toBe(3)
        ->and($otherTransaction->fresh()->category_id)->toBeNull();
});

test('a user cannot accept or dismiss another users payee', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->create();
    $payee = reviewPayee($other, $otherAccount, 'THEIR GROCER', 3000, $this->groceries);

    Livewire::actingAs($this->user)
        ->test(PayeeReview::class)
        ->call('accept', $payee->id)
        ->call('dismiss', $payee->id);

    expect($payee->fresh()->status)->toBe(PayeeStatus::Pending)
        ->and(Transaction::query()->where('user_id', $other->id)->whereNotNull('category_id')->count())->toBe(0);
});

test('a confirmed payee is never listed again and its rows are not overwritten by a later accept all', function () {
    $payee = reviewPayee($this->user, $this->account, 'FRESH GROCER', 3000, $this->groceries);

    Livewire::actingAs($this->user)
        ->test(PayeeReview::class)
        ->set('categoryChoices.'.$payee->id, $this->clothes->id)
        ->call('accept', $payee->id);

    Livewire::actingAs($this->user)
        ->test(PayeeReview::class)
        ->assertDontSee('FRESH GROCER')
        ->call('acceptAll');

    expect(Transaction::query()->where('merchant_key', $payee->merchant_key)->where('category_id', $this->clothes->id)->count())->toBe(3);
});

test('dismissing removes the payee from the list without categorising anything', function () {
    $payee = reviewPayee($this->user, $this->account, 'FRESH GROCER', 3000, $this->groceries);

    Livewire::actingAs($this->user)
        ->test(PayeeReview::class)
        ->call('dismiss', $payee->id)
        ->assertDontSee('FRESH GROCER');

    expect($payee->fresh()->status)->toBe(PayeeStatus::Dismissed)
        ->and(Transaction::query()->where('merchant_key', $payee->merchant_key)->whereNotNull('category_id')->count())->toBe(0);
});

test('choosing work narrows an ambiguous payee to work categories and personal to personal ones', function () {
    $payee = reviewPayee($this->user, $this->account, 'APPLE.COM/BILL', 1999, null);

    $component = Livewire::actingAs($this->user)
        ->test(PayeeReview::class)
        ->set('intents.'.$payee->id, 'work')
        ->assertSee('Software & Online Services / AI Apps')
        ->assertDontSee('Personal & Shopping / Clothes')
        ->assertSet('categoryChoices.'.$payee->id, $this->software->id);

    $component->set('intents.'.$payee->id, 'personal')
        ->assertSee('Personal & Shopping / Clothes')
        ->assertDontSee('Software & Online Services / AI Apps');
});

test('a payee that is not ambiguous gets no work or personal choice', function () {
    reviewPayee($this->user, $this->account, 'FRESH GROCER', 3000, $this->groceries);

    Livewire::actingAs($this->user)
        ->test(PayeeReview::class)
        ->assertDontSee('Is this for work or personal?');
});

test('accept all confirms every suggested payee, not just the first page of the list', function () {
    foreach (range(1, 52) as $number) {
        reviewPayee($this->user, $this->account, 'CAFE '.chr(65 + intdiv($number, 26)).chr(65 + $number % 26).' SHOP', 1000 + $number, $this->groceries, 1);
    }

    Livewire::actingAs($this->user)
        ->test(PayeeReview::class)
        ->call('acceptAll');

    expect(Payee::query()->where('user_id', $this->user->id)->where('status', PayeeStatus::Pending)->count())->toBe(0)
        ->and(Payee::query()->where('user_id', $this->user->id)->where('status', PayeeStatus::Confirmed)->count())->toBe(52);
});

test('the category select is live so the tag badge follows the choice', function () {
    $payee = reviewPayee($this->user, $this->account, 'FRESH GROCER', 3000, $this->groceries);

    $badge = fn (string $html): string => preg_match('/data-test="payee-tag"[^>]*>\s*([A-Za-z]+)\s*</', $html, $matches) === 1 ? $matches[1] : '';

    $component = Livewire::actingAs($this->user)
        ->test(PayeeReview::class)
        ->assertSeeHtml('wire:model.live="categoryChoices.'.$payee->id.'"');

    expect($badge($component->html()))->toBe('Needs');

    $component->set('categoryChoices.'.$payee->id, $this->clothes->id);

    expect($badge($component->html()))->toBe('Wants');
});

test('the rules page shows the payee review', function () {
    reviewPayee($this->user, $this->account, 'FRESH GROCER', 3000, $this->groceries);

    $this->actingAs($this->user)
        ->get(route('rules'))
        ->assertOk()
        ->assertSeeLivewire(PayeeReview::class)
        ->assertSee('Check your biggest payees');
});
