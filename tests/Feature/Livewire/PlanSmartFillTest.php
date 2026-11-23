<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\TypeSafeServiceContract;
use App\DTOs\TypeSafeChoiceAnswer;
use App\Enums\PlannedTransactionType;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Exceptions\TypeSafe\TypeSafeException;
use App\Livewire\PlanDeclarationForm;
use App\Livewire\PlannedTransactionManager;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Recurring\PlanSmartFiller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function () {
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-20 12:00:00', 'UTC'));
    config(['budget.plan_smart_fill' => true, 'services.typesafe.api_key' => 'ts_test']);
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->user->update(['primary_account_id' => $this->account->id]);
    $this->category = Category::factory()->for($this->user)->create(['name' => 'Entertainment']);
    $this->otherCategory = Category::factory()->for($this->user)->create(['name' => 'Utilities']);
});

function smartFillModel(array $choices = [], ?Throwable $throws = null, float $confidence = 0.9): object
{
    $fake = new class($choices, $throws, $confidence) implements TypeSafeServiceContract
    {
        /** @var list<array{state: array<string, mixed>, instructions: string, options: array<string, string>}> */
        public array $calls = [];

        public function __construct(private array $choices, private ?Throwable $throws, private float $confidence) {}

        public function choose(array $state, string $instructions, array $options): TypeSafeChoiceAnswer
        {
            $this->calls[] = ['state' => $state, 'instructions' => $instructions, 'options' => $options];

            if ($this->throws !== null) {
                throw $this->throws;
            }

            $field = match (true) {
                str_contains($instructions, 'How often') => 'frequency',
                str_contains($instructions, 'kind of payment') => 'type',
                str_contains($instructions, 'taken automatically') => 'autopay',
                str_contains($instructions, 'best name') => 'name',
                str_contains($instructions, 'usual amount') => 'amount',
                default => 'category',
            };
            $choice = (string) ($this->choices[$field] ?? array_key_first($options));

            return new TypeSafeChoiceAnswer($choice, [$choice => 0.9], $this->confidence, 'fake', 1, 1);
        }
    };

    app()->when(PlanSmartFiller::class)->needs(TypeSafeServiceContract::class)->give(fn () => $fake);

    return $fake;
}

function smartFillCharges(object $test, string $description = 'Netflix', int $amount = 1699, array $dates = ['2026-01-15', '2026-02-15', '2026-03-15'], ?User $user = null, ?Account $account = null, ?int $categoryId = null): void
{
    foreach ($dates as $date) {
        Transaction::factory()->for($user ?? $test->user)->for($account ?? $test->account)->create([
            'description' => $description,
            'amount' => -$amount,
            'direction' => TransactionDirection::Debit,
            'post_date' => $date,
            'category_id' => $categoryId,
        ]);
    }
}

function smartFillForm(object $test)
{
    return Livewire::actingAs($test->user)->test(PlanDeclarationForm::class)->call('open', 'bill')->set('description', 'Netflix');
}

it('hides smart fill when the flag is off', function () {
    config(['budget.plan_smart_fill' => false]);
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create();

    smartFillForm($this)->assertDontSeeHtml('data-testid="declare-smart-fill"')->call('smartFill')->assertNotFound();

    Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)
        ->assertDontSeeHtml("data-testid=\"plan-smart-configure-{$plan->id}\"")
        ->call('smartConfigure', $plan->id)->assertNotFound();
});

it('hides smart fill when no model key is configured', function () {
    config(['services.typesafe.api_key' => null]);

    smartFillForm($this)->assertDontSeeHtml('data-testid="declare-smart-fill"');
});

it('shows smart fill when the flag and key are set', function () {
    smartFillForm($this)->assertSeeHtml('data-testid="declare-smart-fill-button"');
});

it('proposes smart fill values without saving the plan', function () {
    smartFillCharges($this, categoryId: $this->category->id);
    $fake = smartFillModel(['frequency' => 'every-month', 'type' => 'subscription', 'autopay' => 'yes', 'category' => (string) $this->category->id]);

    smartFillForm($this)
        ->call('smartFill')
        ->assertHasNoErrors()
        ->assertSet('description', 'Netflix')
        ->assertSet('amount', '16.99')
        ->assertSet('frequency', 'every-month')
        ->assertSet('startDate', '2026-04-15')
        ->assertSet('categoryId', $this->category->id)
        ->assertSet('planType', 'subscription')
        ->assertSet('autopay', true)
        ->assertSee('nothing is saved until you add the plan');

    expect(PlannedTransaction::query()->count())->toBe(0)
        ->and($fake->calls)->not->toBeEmpty();
});

it('saves the proposed values only when the plan is added', function () {
    smartFillCharges($this);
    smartFillModel(['frequency' => 'every-month', 'type' => 'subscription', 'autopay' => 'yes']);

    smartFillForm($this)->call('smartFill')->call('save')->assertHasNoErrors();

    $plan = PlannedTransaction::query()->sole();

    expect($plan->type)->toBe(PlannedTransactionType::Subscription)
        ->and($plan->autopay)->toBeTrue()
        ->and($plan->amount)->toBe(1699)
        ->and($plan->frequency)->toBe(RecurrenceFrequency::EveryMonth);
});

it('keeps the plain prefill when smart fill fails', function () {
    smartFillCharges($this);
    smartFillModel(throws: new TypeSafeException('TypeSafe returned HTTP 500: secret body'));
    Log::spy();

    smartFillForm($this)
        ->set('amount', '12.00')
        ->set('frequency', 'every-week')
        ->call('smartFill')
        ->assertHasErrors(['smartFill'])
        ->assertSet('description', 'Netflix')
        ->assertSet('amount', '12.00')
        ->assertSet('frequency', 'every-week')
        ->assertSee('Your form is unchanged.');

    Log::shouldHaveReceived('warning')->once()->with('Plan smart fill failed', ['user_id' => $this->user->id, 'exception' => TypeSafeException::class]);
    expect(PlannedTransaction::query()->count())->toBe(0);
});

it('sends at most forty of the users own charges to smart fill', function () {
    $dates = array_map(fn (int $day): string => CarbonImmutable::parse('2026-03-19')->subDays($day)->toDateString(), range(0, 59));
    smartFillCharges($this, 'Netflix 4412', 1699, $dates);
    $other = User::factory()->create();
    smartFillCharges($this, 'Netflix Other Person', 999, ['2026-03-18'], $other, Account::factory()->for($other)->create());
    $fake = smartFillModel();

    smartFillForm($this)->call('smartFill')->assertHasNoErrors();

    $charges = $fake->calls[0]['state']['charges'];

    expect($charges)->toHaveCount(40)
        ->and(collect($charges)->pluck('description')->unique()->all())->toBe(['Netflix #'])
        ->and(array_keys($charges[0]))->toBe(['description', 'amount', 'date'])
        ->and(json_encode($fake->calls))->not->toContain('Other Person')
        ->and($charges[0]['date'])->toBe('2026-03-19');
});

it('leaves a field alone when the model is not sure enough', function () {
    smartFillCharges($this);
    smartFillModel(confidence: 0.4);

    smartFillForm($this)
        ->set('amount', '12.00')
        ->call('smartFill')
        ->assertHasNoErrors()
        ->assertSet('amount', '16.99')
        ->assertSet('frequency', 'every-month')
        ->assertSet('startDate', '2026-03-20')
        ->assertSet('planType', 'bill')
        ->assertSet('autopay', false);
});

it('says so when no past charge matches', function () {
    $fake = smartFillModel();

    smartFillForm($this)->set('description', 'Brand new biller')->call('smartFill')->assertHasNoErrors()->assertSee('nothing to fill from');

    expect($fake->calls)->toBe([]);
});

it('needs a description before it asks anything', function () {
    $fake = smartFillModel();

    smartFillForm($this)->set('description', '  ')->call('smartFill')->assertHasErrors(['smartFill']);

    expect($fake->calls)->toBe([]);
});

it('limits how often a user can smart fill', function () {
    smartFillCharges($this);
    smartFillModel();
    RateLimiter::clear('plan-smart-fill:'.$this->user->id);

    $form = smartFillForm($this);

    foreach (range(1, PlanSmartFiller::HOURLY_LIMIT) as $attempt) {
        $form->call('smartFill')->assertHasNoErrors();
    }

    $form->call('smartFill')->assertHasErrors(['smartFill'])->assertSee('a lot this hour');
});

it('asks the model to choose among the distinct names and amounts', function () {
    smartFillCharges($this, 'Netflix', 1699, ['2026-01-15']);
    smartFillCharges($this, 'Netflix Premium', 2299, ['2026-02-15', '2026-03-15']);
    $fake = smartFillModel(['name' => 'n1', 'amount' => '1699']);

    smartFillForm($this)->set('description', 'Netfl')->call('smartFill')->assertSet('description', 'Netflix')->assertSet('amount', '16.99');

    $instructions = collect($fake->calls)->pluck('instructions')->implode(' | ');

    expect($instructions)->toContain('best name')->toContain('usual amount');
});

it('proposes income from past deposits and leaves autopay off', function () {
    foreach (['2026-02-13', '2026-02-27', '2026-03-13'] as $date) {
        Transaction::factory()->for($this->user)->for($this->account)->create(['description' => 'Pay run', 'amount' => 250000, 'direction' => TransactionDirection::Credit, 'post_date' => $date]);
    }
    $fake = smartFillModel(['frequency' => 'every-2-weeks', 'type' => 'income', 'autopay' => 'yes']);

    Livewire::actingAs($this->user)->test(PlanDeclarationForm::class)->call('open', 'income')->set('description', 'Pay run')
        ->call('smartFill')
        ->assertSet('amount', '2500.00')
        ->assertSet('frequency', 'every-2-weeks')
        ->assertSet('startDate', '2026-03-27')
        ->assertSet('autopay', false)
        ->assertSet('planType', 'income');

    expect(collect($fake->calls)->pluck('instructions')->implode(' | '))->not->toContain('taken automatically');
});

it('audits smart fill and records a failure as one', function () {
    smartFillCharges($this);
    smartFillModel(throws: new TypeSafeException('down'));

    smartFillForm($this)->call('smartFill');

    expect(AuditEvent::query()->where('action', 'livewire.plan-declaration-form.smartFill')->sole()->outcome->value)->toBe('failure');
});

it('suggests only fields that contradict current settings', function () {
    smartFillCharges($this, categoryId: $this->category->id);
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create([
        'description' => 'Netflix',
        'amount' => 1499,
        'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-01-15',
        'type' => PlannedTransactionType::Subscription,
        'category_id' => $this->category->id,
        'autopay' => true,
    ]);
    smartFillModel(['frequency' => 'every-month', 'type' => 'subscription', 'autopay' => 'yes', 'category' => (string) $this->category->id]);

    $component = Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)->call('smartConfigure', $plan->id)->assertHasNoErrors();

    expect(collect($component->get('smartConfigureChanges'))->pluck('field')->all())->toBe(['Amount'])
        ->and($component->get('smartConfigureChanges')[0]['suggested'])->toBe('$16.99');

    $component->assertSeeHtml('data-testid="plan-smart-configure-change"');
    expect($plan->fresh()->amount)->toBe(1499);
});

it('lists every field that contradicts the plan and says so when none do', function () {
    smartFillCharges($this, categoryId: $this->category->id);
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create([
        'description' => 'Netflix',
        'amount' => 1699,
        'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::EveryWeek,
        'start_date' => '2026-01-10',
        'type' => PlannedTransactionType::Bill,
        'category_id' => $this->otherCategory->id,
        'autopay' => false,
    ]);
    smartFillModel(['frequency' => 'every-month', 'type' => 'subscription', 'autopay' => 'yes', 'category' => (string) $this->category->id]);

    $manager = Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)->call('smartConfigure', $plan->id);

    expect(collect($manager->get('smartConfigureChanges'))->pluck('field')->all())->toBe(['Repeats', 'Type', 'Category', 'Autopay']);

    $plan->update(['frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-01-15', 'type' => PlannedTransactionType::Subscription, 'category_id' => $this->category->id, 'autopay' => true]);

    Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)->call('smartConfigure', $plan->id)->assertSee('contradicts this plan');
});

it('keeps the plan and shows a soft notice when smart configure fails', function () {
    smartFillCharges($this);
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['description' => 'Netflix', 'amount' => 1499, 'direction' => TransactionDirection::Debit]);
    smartFillModel(throws: new TypeSafeException('down'));

    Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)->call('smartConfigure', $plan->id)->assertHasErrors(['smartConfigure'])->assertSee('Your plan is unchanged.');

    expect($plan->fresh()->amount)->toBe(1499);
});

it('does not smart configure another users plan', function () {
    $other = User::factory()->create();
    $theirs = PlannedTransaction::factory()->for($other)->for(Account::factory()->for($other))->create(['description' => 'Netflix']);
    smartFillCharges($this);
    $fake = smartFillModel();

    Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)->call('smartConfigure', $theirs->id)->assertSet('smartConfigurePlanId', null);

    expect($fake->calls)->toBe([]);
});

it('builds the interactive model client with a short timeout and no retries', function () {
    config(['services.typesafe.interactive_timeout' => 7]);
    $seen = [];
    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen[] = $options['timeout'] ?? null;

        return Http::response([], 529);
    });
    smartFillCharges($this);

    expect(fn () => app(PlanSmartFiller::class)->suggest($this->user, 'Netflix', TransactionDirection::Debit))->toThrow(TypeSafeException::class)
        ->and($seen)->toBe([7]);
});

it('suggests a different due day only when the cadence itself is not contradicted', function () {
    smartFillCharges($this);
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create([
        'description' => 'Netflix',
        'amount' => 1699,
        'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-01-10',
        'type' => PlannedTransactionType::Subscription,
        'autopay' => false,
    ]);
    smartFillModel(['frequency' => 'every-month', 'type' => 'subscription', 'autopay' => 'no']);

    $manager = Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)->call('smartConfigure', $plan->id);

    expect($manager->get('smartConfigureChanges'))->toBe([['field' => 'Next due', 'current' => '2026-04-10', 'suggested' => '2026-04-15']]);
});

it('does not call a bill due on the 31st contradicted by a short month', function () {
    smartFillCharges($this, dates: ['2026-01-31', '2026-02-28']);
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create([
        'description' => 'Netflix',
        'amount' => 1699,
        'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-01-31',
        'type' => PlannedTransactionType::Subscription,
        'autopay' => false,
    ]);
    smartFillModel(['frequency' => 'every-month', 'type' => 'subscription', 'autopay' => 'no']);

    $manager = Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)->call('smartConfigure', $plan->id);

    expect($manager->get('smartConfigureChanges'))->toBe([])
        ->and($manager->get('smartConfigureNotice'))->toContain('contradicts this plan');
});

it('clears the smart configure result once the plan is edited', function () {
    smartFillCharges($this);
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['description' => 'Netflix', 'amount' => 1499, 'direction' => TransactionDirection::Debit]);
    smartFillModel();

    Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)
        ->call('smartConfigure', $plan->id)
        ->assertSet('smartConfigurePlanId', $plan->id)
        ->call('openEdit', $plan->id)
        ->set('amount', '16.99')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('smartConfigurePlanId', null)
        ->assertSet('smartConfigureChanges', []);
});

it('does not ask about the name when smart configure has no use for it', function () {
    smartFillCharges($this, 'Netflix', 1699, ['2026-01-15']);
    smartFillCharges($this, 'Netflix Premium', 2299, ['2026-02-15', '2026-03-15']);
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['description' => 'Netflix', 'amount' => 1699, 'direction' => TransactionDirection::Debit]);
    $fake = smartFillModel();

    Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)->call('smartConfigure', $plan->id);

    expect(collect($fake->calls)->pluck('instructions')->implode(' | '))->not->toContain('best name');
});

it('only sends charges in the currency of the account', function () {
    $usd = Account::factory()->for($this->user)->create(['currency' => 'USD']);
    smartFillCharges($this, 'Netflix', 999, ['2026-03-10'], account: $usd);
    Transaction::query()->where('account_id', $usd->id)->update(['currency' => 'USD']);
    smartFillCharges($this);
    $fake = smartFillModel();

    smartFillForm($this)->call('smartFill')->assertSet('amount', '16.99');

    expect(collect($fake->calls[0]['state']['charges'])->pluck('amount')->unique()->all())->toBe([1699]);
});

it('does not leave an old field error or a failed audit behind after a good run', function () {
    smartFillCharges($this);
    smartFillModel();

    smartFillForm($this)->set('amount', 'abc')->call('save')->assertHasErrors(['amount'])->call('smartFill')->assertHasNoErrors();

    expect(AuditEvent::query()->where('action', 'livewire.plan-declaration-form.smartFill')->sole()->outcome->value)->toBe('success');
});

it('clears the smart fill notice when a pattern or transaction prefills instead', function () {
    smartFillCharges($this);
    smartFillModel();
    $row = Transaction::query()->where('user_id', $this->user->id)->first();

    smartFillForm($this)->call('smartFill')->assertSee('Smart fill proposed')->call('startFromTransaction', $row->id)->assertDontSee('Smart fill proposed');
});
