<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Livewire\PlanDeclarationForm;
use App\Models\Account;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-20 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->user->update(['primary_account_id' => $this->account->id]);
});

function pickerSeries(object $test, string $name, int $amount, array $dates = ['2026-01-15', '2026-02-15', '2026-03-15'], TransactionDirection $direction = TransactionDirection::Debit, ?User $user = null, ?Account $account = null): void
{
    foreach ($dates as $date) {
        Transaction::factory()->fromRedbark()->for($user ?? $test->user)->for($account ?? $test->account)->create([
            'merchant_name' => $name,
            'amount' => $direction === TransactionDirection::Debit ? -$amount : $amount,
            'direction' => $direction,
            'post_date' => CarbonImmutable::parse($date),
        ]);
    }
}

function pickerForm(object $test, string $kind = 'bill')
{
    return Livewire::actingAs($test->user)->test(PlanDeclarationForm::class)->call('open', $kind);
}

function pickerNames($form): array
{
    return $form->instance()->patterns->map(fn ($pattern): string => $pattern->candidate->cleanDescription)->all();
}

it('offers only patterns not covered by a plan', function () {
    pickerSeries($this, 'Netflix', 1699);
    pickerSeries($this, 'Spotify', 1199);
    PlannedTransaction::factory()->for($this->user)->for($this->account)->create([
        'description' => 'Spotify',
        'amount' => 1199,
        'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::EveryMonth,
        'is_active' => true,
    ]);

    $form = pickerForm($this);

    expect(pickerNames($form))->toBe(['Netflix']);

    $form->assertSee('Netflix')->assertDontSee('Spotify');
});

it('hides patterns under one dollar', function () {
    pickerSeries($this, 'Tiny fee', 80);
    pickerSeries($this, 'Gym', 100);

    expect(pickerNames(pickerForm($this)))->toBe(['Gym']);
});

it('caps the pattern picker at eight', function () {
    foreach (['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf', 'Hotel', 'India', 'Juliet', 'Kilo', 'Lima'] as $name) {
        pickerSeries($this, "{$name} Telco", 1500);
    }

    expect(pickerNames(pickerForm($this)))->toHaveCount(8);
});

it('offers income by the largest source first and only with two deposits in ninety days', function () {
    pickerSeries($this, 'Small side job', 20000, ['2026-02-12', '2026-02-26', '2026-03-12'], TransactionDirection::Credit);
    pickerSeries($this, 'Salary', 300000, ['2026-02-13', '2026-02-27', '2026-03-13'], TransactionDirection::Credit);
    pickerSeries($this, 'Yearly refund', 500000, ['2025-03-18', '2026-03-18'], TransactionDirection::Credit);
    pickerSeries($this, 'Netflix', 1699);

    $form = pickerForm($this, 'income');

    expect(pickerNames($form))->toBe(['Salary', 'Small side job']);
});

it('never searches another users transactions', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->create();
    pickerSeries($this, 'Netflix', 1699, ['2026-03-15']);
    pickerSeries($this, 'Netflix Premium', 2299, ['2026-03-16'], user: $other, account: $otherAccount);

    $form = pickerForm($this)->set('searchTerm', 'Netflix');

    expect($form->instance()->searchResults->pluck('merchant_name')->all())->toBe(['Netflix'])
        ->and($form->instance()->searchResults->pluck('user_id')->unique()->all())->toBe([$this->user->id]);

    $form->assertDontSee('Netflix Premium');
});

it('labels searched rows already paying a plan and leaves out transfers', function () {
    $rent = PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['description' => 'Rent']);
    $transferCategory = Category::factory()->for($this->user)->create(['name' => 'Transfer']);
    Transaction::factory()->for($this->user)->for($this->account)->create(['description' => 'Rent payment', 'planned_transaction_id' => $rent->id, 'post_date' => '2026-03-02']);
    Transaction::factory()->for($this->user)->for($this->account)->create(['description' => 'Rent to savings', 'category_id' => $transferCategory->id, 'post_date' => '2026-03-03']);
    Transaction::factory()->for($this->user)->for($this->account)->create(['description' => 'Rent excluded', 'excluded' => true, 'post_date' => '2026-03-04']);

    $form = pickerForm($this)->set('searchTerm', 'Rent');

    expect($form->instance()->searchResults->pluck('description')->all())->toBe(['Rent payment']);

    $form->assertSeeHtmlInOrder(['data-testid="declare-found-plan"', 'Part of Rent'])->assertDontSee('Rent to savings');
});

it('prefills income from a credit transaction', function () {
    $credit = Transaction::factory()->for($this->user)->for($this->account)->create([
        'description' => 'Pay run',
        'amount' => 250000,
        'direction' => TransactionDirection::Credit,
        'post_date' => '2026-03-13',
    ]);

    pickerForm($this)
        ->assertSet('kind', 'bill')
        ->set('paymentUrl', 'bill.example/pay')
        ->set('autopay', true)
        ->call('startFromTransaction', $credit->id)
        ->assertSet('kind', 'income')
        ->assertSet('amount', '2500.00')
        ->assertSet('description', 'Pay run')
        ->assertSet('accountId', $this->account->id)
        ->assertSet('frequency', 'every-2-weeks')
        ->assertSet('startDate', '2026-03-27')
        ->assertSet('paymentUrl', '')
        ->assertSet('autopay', false);
});

it('prefills a bill from a debit transaction with its next due day', function () {
    $category = Category::factory()->for($this->user)->create();
    $merchant = Merchant::factory()->for($this->user)->create();
    $debit = Transaction::factory()->for($this->user)->for($this->account)->create([
        'description' => 'ISP direct debit',
        'amount' => 7995,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-02-10',
        'category_id' => $category->id,
        'merchant_id' => $merchant->id,
    ]);

    pickerForm($this)
        ->call('startFromTransaction', $debit->id)
        ->assertSet('kind', 'bill')
        ->assertSet('amount', '79.95')
        ->assertSet('frequency', 'every-month')
        ->assertSet('startDate', '2026-04-10')
        ->assertSet('categoryId', $category->id)
        ->assertSet('merchantId', $merchant->id);
});

it('prefills the form from a detected pattern and saves it', function () {
    pickerSeries($this, 'Netflix', 1699);

    $form = pickerForm($this);
    $key = $form->instance()->patterns->first()->key;

    expect($form->instance()->patterns->first()->candidate->amount)->toBe(-1699);

    $form->call('usePattern', $key)
        ->assertSet('description', 'Netflix')
        ->assertSet('amount', '16.99')
        ->assertSet('frequency', 'every-month')
        ->assertSet('startDate', '2026-04-15')
        ->call('save')
        ->assertHasNoErrors();

    expect(PlannedTransaction::query()->where('description', 'Netflix')->sole()->amount)->toBe(1699);

    expect(pickerNames(pickerForm($this)))->toBe([]);
});

it('refuses an unknown pattern key', function () {
    pickerSeries($this, 'Netflix', 1699);

    pickerForm($this)->call('usePattern', 'nope')->assertHasErrors(['pattern'])->assertSet('description', '');
});

it('answers not found for a transaction of another user or a transfer', function () {
    $other = User::factory()->create();
    $foreign = Transaction::factory()->for($other)->for(Account::factory()->for($other))->create();
    $transferCategory = Category::factory()->for($this->user)->create(['name' => 'Transfer']);
    $transfer = Transaction::factory()->for($this->user)->for($this->account)->create(['category_id' => $transferCategory->id]);

    pickerForm($this)->call('startFromTransaction', $foreign->id)->assertNotFound();
    pickerForm($this)->call('startFromTransaction', $transfer->id)->assertNotFound();
});

it('pages the search twenty at a time, newest first', function () {
    foreach (range(1, 25) as $day) {
        Transaction::factory()->for($this->user)->for($this->account)->create([
            'description' => "Parking {$day}",
            'post_date' => CarbonImmutable::parse('2026-03-01')->addDays($day % 19),
        ]);
    }

    $form = pickerForm($this)->set('searchTerm', 'Parking');

    expect($form->instance()->searchResults)->toHaveCount(20)
        ->and($form->instance()->searchResults->total())->toBe(25)
        ->and($form->instance()->searchResults->first()->description)->toBe('Parking 18');

    $form->call('nextPage', 'searchPage');

    expect($form->instance()->searchResults->pluck('description')->all())->toBe(['Parking 21', 'Parking 2', 'Parking 20', 'Parking 1', 'Parking 19']);
});

it('searches by notes and merchant name and treats percent and underscore literally', function () {
    Transaction::factory()->for($this->user)->for($this->account)->create(['description' => 'Card 1', 'notes' => 'annual domain renewal']);
    Transaction::factory()->for($this->user)->for($this->account)->create(['description' => 'Card 2', 'merchant_name' => 'Hosting Co']);
    Transaction::factory()->for($this->user)->for($this->account)->create(['description' => '100% fee']);
    Transaction::factory()->for($this->user)->for($this->account)->create(['description' => 'Other']);

    $found = fn (string $term): array => pickerForm($this)->set('searchTerm', $term)->instance()->searchResults->pluck('description')->sort()->values()->all();

    expect($found('domain'))->toBe(['Card 1'])
        ->and($found('hosting'))->toBe(['Card 2'])
        ->and($found('%'))->toBe(['100% fee'])
        ->and($found('_'))->toBe([]);
});

it('shows nothing for a blank search', function () {
    Transaction::factory()->for($this->user)->for($this->account)->create(['description' => 'Anything']);

    expect(pickerForm($this)->set('searchTerm', '   ')->instance()->searchResults)->toBeNull();
});

it('hides patterns on an account that is switched off', function () {
    $off = Account::factory()->for($this->user)->create(['status' => App\Enums\AccountStatus::Inactive]);
    pickerSeries($this, 'Netflix', 1699, account: $off);

    expect(pickerNames(pickerForm($this)))->toBe([]);
});

it('keeps a payment to an untracked account searchable', function () {
    $untracked = Account::factory()->for($this->user)->untracked()->create();
    $leg = Transaction::factory()->for($this->user)->for($this->account)->create(['description' => 'Home loan repayment', 'post_date' => '2026-03-05']);
    $mirror = Transaction::factory()->for($this->user)->for($untracked)->create(['description' => 'Home loan repayment received', 'post_date' => '2026-03-05', 'transfer_pair_id' => $leg->id]);
    $leg->update(['transfer_pair_id' => $mirror->id]);

    expect(pickerForm($this)->set('searchTerm', 'Home loan')->instance()->searchResults->pluck('description')->all())->toBe(['Home loan repayment']);
});
