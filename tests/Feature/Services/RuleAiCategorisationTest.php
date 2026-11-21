<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BudgetTag;
use App\Enums\CategorySource;
use App\Enums\PayeeStatus;
use App\Enums\RuleActionType;
use App\Jobs\CategoriseRuleMatchesJob;
use App\Livewire\UserRuleManager;
use App\Models\Account;
use App\Models\Category;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\Payees\PayeeReviewQueue;
use App\Services\RuleActionExecutor;
use App\Services\RuleTester;
use App\Services\UserRuleApplier;
use App\Support\Audit\AttributeJobToUser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['services.typesafe.api_key' => 'ts_test', 'budget.rule_ai_categorisation' => true]);
    Sleep::fake();
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries', 'parent_id' => null, 'budget_tag' => BudgetTag::Needs]);
    $this->fuel = Category::factory()->for($this->user)->create(['name' => 'Fuel', 'parent_id' => null, 'budget_tag' => BudgetTag::Needs]);
});

function aiRow(User $user, Account $account, string $merchant = 'Corner Store', array $overrides = []): Transaction
{
    return Transaction::factory()->for($user)->for($account)->debit()->create([
        'description' => 'CARD 4455 '.$merchant.'  REF 998877',
        'merchant_name' => $merchant,
        'amount' => 2345,
        'category_id' => null,
        'category_source' => null,
        ...$overrides,
    ]);
}

function aiJev(string $answerPath, float $confidence = 0.95): void
{
    Http::fake(function (Request $request) use ($answerPath, $confidence) {
        $criteria = $request['questions']['answer']['criteria'];
        $choice = array_key_first($criteria);

        foreach ($criteria as $id => $path) {
            if ($path === $answerPath) {
                $choice = $id;
            }
        }

        $others = max(1, count($criteria) - 1);
        $probabilities = [$choice => $confidence];

        foreach (array_keys($criteria) as $id) {
            if ($id !== $choice) {
                $probabilities[$id] = (1 - $confidence) / $others;
            }
        }

        return Http::response([
            'model' => 'jev-1.13.0',
            'answers' => ['answer' => ['type' => 'choice', 'choice' => $choice, 'probabilities' => $probabilities, 'confidence' => $confidence]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 3],
        ]);
    });
}

function aiRule(User $user): UserRule
{
    $group = UserRuleGroup::factory()->for($user)->create(['order' => 1]);

    return UserRule::factory()->for($user)->for($group, 'group')->create([
        'triggers' => [['field' => 'merchant_name', 'operator' => 'equals', 'value' => 'Corner Store']],
        'actions' => [['type' => RuleActionType::SuggestCategoryWithAi->value, 'value' => '']],
        'is_auto_apply' => true,
    ]);
}

it('makes no TypeSafe call when previewing an AI rule', function () {
    Queue::fake();
    Http::fake();
    $rule = aiRule($this->user);
    aiRow($this->user, $this->account);
    $executor = app(RuleActionExecutor::class);

    $preview = app(RuleTester::class)->test($this->user->id, $rule->triggers, true, $rule->actions);
    $changes = $executor->wouldChange(Transaction::query()->firstOrFail(), $rule->actions);
    app(UserRuleApplier::class)->applyToHistory($rule);

    Http::assertNothingSent();
    expect($preview->notYetAppliedCount)->toBe(0)
        ->and($changes)->toBeFalse();
});

it('queues one categorise job per merchant key when the action runs and none for a preview', function () {
    Queue::fake();
    $rule = aiRule($this->user);
    $other = aiRow($this->user, $this->account, 'Other Shop');
    foreach (range(1, 3) as $ignored) {
        aiRow($this->user, $this->account);
    }

    app(RuleTester::class)->test($this->user->id, $rule->triggers, true, $rule->actions);
    Queue::assertNothingPushed();

    app(UserRuleApplier::class)->applyToHistory($rule);

    Queue::assertPushed(CategoriseRuleMatchesJob::class, 1);
    expect($other->fresh()->category_id)->toBeNull();
});

it('queues nothing while the feature is off', function () {
    config(['budget.rule_ai_categorisation' => false]);
    Queue::fake();
    $rule = aiRule($this->user);
    aiRow($this->user, $this->account);

    app(UserRuleApplier::class)->applyToHistory($rule);

    Queue::assertNothingPushed();
});

it('asks Jev once per matched merchant key', function () {
    aiJev('Groceries');
    $rows = array_map(fn (): Transaction => aiRow($this->user, $this->account), [1, 2, 3]);

    new CategoriseRuleMatchesJob($this->user, array_map(fn (Transaction $t): int => $t->id, $rows))->handle();

    Http::assertSentCount(1);
    expect(collect($rows)->map(fn (Transaction $t): ?int => $t->fresh()->category_id)->unique()->all())->toBe([$this->groceries->id])
        ->and($rows[0]->fresh()->category_source)->toBe(CategorySource::Suggested);
});

it('leaves low-confidence matches uncategorised for payee review', function () {
    aiJev('Groceries', 0.6);
    $row = aiRow($this->user, $this->account);

    new CategoriseRuleMatchesJob($this->user, [$row->id])->handle();

    $payee = Payee::query()->where('user_id', $this->user->id)->sole();

    expect($row->fresh()->category_id)->toBeNull()
        ->and($payee->status)->toBe(PayeeStatus::Pending)
        ->and($payee->auto_applied)->toBeFalse()
        ->and(app(PayeeReviewQueue::class)->pending($this->user)->count())->toBe(1);
});

it('skips income and merchants that are already known', function () {
    aiJev('Groceries');
    $credit = Transaction::factory()->for($this->user)->for($this->account)->credit()->create(['merchant_name' => 'Corner Store', 'description' => 'REFUND Corner Store', 'category_id' => null]);
    $known = aiRow($this->user, $this->account, 'Known Shop');
    Payee::factory()->create(['user_id' => $this->user->id, 'merchant_key' => $known->merchant_key, 'merchant_name' => 'Known Shop']);

    new CategoriseRuleMatchesJob($this->user, [$credit->id, $known->id])->handle();

    Http::assertNothingSent();
    expect($credit->fresh()->category_id)->toBeNull()
        ->and($known->fresh()->category_id)->toBeNull();
});

it('categorises only the job user transactions', function () {
    aiJev('Groceries');
    $stranger = User::factory()->create();
    $strangerAccount = Account::factory()->for($stranger)->create();
    $mine = aiRow($this->user, $this->account);
    $theirs = aiRow($stranger, $strangerAccount);

    new CategoriseRuleMatchesJob($this->user, [$mine->id, $theirs->id])->handle();

    expect($mine->fresh()->category_id)->toBe($this->groceries->id)
        ->and($theirs->fresh()->category_id)->toBeNull()
        ->and(Payee::query()->where('user_id', $stranger->id)->count())->toBe(0);
    Http::assertSentCount(1);
});

it('does nothing when the feature is off or the key is missing', function (array $config) {
    config($config);
    Http::fake();
    $row = aiRow($this->user, $this->account);

    new CategoriseRuleMatchesJob($this->user, [$row->id])->handle();

    Http::assertNothingSent();
    expect($row->fresh()->category_id)->toBeNull();
})->with([
    'flag off' => [['budget.rule_ai_categorisation' => false]],
    'no key' => [['services.typesafe.api_key' => null]],
]);

it('sends only the masked merchant name to TypeSafe', function () {
    aiJev('Groceries');
    $row = aiRow($this->user, $this->account, 'Corner Store 1234');

    new CategoriseRuleMatchesJob($this->user, [$row->id])->handle();

    $payloads = Http::recorded()->map(fn (array $pair): array => $pair[0]->data());
    $body = $payloads->map(fn (array $payload): string => json_encode($payload))->implode('');

    expect($payloads)->toHaveCount(1)
        ->and(array_keys($payloads->first()))->toBe(['model', 'state', 'questions'])
        ->and(array_keys($payloads->first()['state']))->toBe(['merchant_name'])
        ->and($payloads->first()['state']['merchant_name'])->toBe(App\Console\Commands\JevEvalCommand::maskDigits('Corner Store 1234'))
        ->and(array_keys($payloads->first()['questions']))->toBe(['answer'])
        ->and(array_keys($payloads->first()['questions']['answer']))->toBe(['type', 'instructions', 'criteria'])
        ->and(array_values($payloads->first()['questions']['answer']['criteria']))->each->toBeIn(['Groceries', 'Fuel'])
        ->and($body)->not->toContain('2345')
        ->and($body)->not->toContain('23.45')
        ->and($body)->not->toContain('998877')
        ->and($body)->not->toContain('CARD 4455')
        ->and($body)->not->toContain((string) $this->user->email)
        ->and($body)->not->toContain((string) $this->account->name);
});

it('sends no transaction, user or account identifiers, only category option keys', function () {
    aiJev('Groceries');
    $row = aiRow($this->user, $this->account);

    new CategoriseRuleMatchesJob($this->user, [$row->id])->handle();

    $keys = [];
    $collect = function (mixed $value) use (&$collect, &$keys): void {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $keys[] = (string) $key;
                $collect($child);
            }
        }
    };
    Http::recorded()->each(fn (array $pair) => $collect($pair[0]->data()));

    $allowed = ['model', 'state', 'merchant_name', 'questions', 'answer', 'type', 'instructions', 'criteria'];
    $unexpected = array_filter($keys, fn (string $key): bool => ! in_array($key, $allowed, true) && ! preg_match('/^c\d+$/', $key));

    expect($unexpected)->toBe([]);
});

it('queues no categorise job when a later action of the same rule fails and rolls back', function () {
    config(['queue.default' => 'database']);
    Event::listen('eloquent.saving: '.Transaction::class, function (Transaction $transaction): void {
        if ($transaction->notes === 'boom') {
            throw new RuntimeException('boom');
        }
    });
    $transaction = aiRow($this->user, $this->account);
    $actions = [
        ['type' => RuleActionType::SuggestCategoryWithAi->value, 'value' => ''],
        ['type' => RuleActionType::SetNotes->value, 'value' => 'boom'],
    ];

    expect(fn () => DB::transaction(fn () => app(RuleActionExecutor::class)->execute($transaction, $actions)))
        ->toThrow(RuntimeException::class)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('queues the categorise job once the surrounding transaction commits', function () {
    config(['queue.default' => 'database']);
    $transaction = aiRow($this->user, $this->account);

    DB::transaction(function () use ($transaction): void {
        app(RuleActionExecutor::class)->execute($transaction, [['type' => RuleActionType::SuggestCategoryWithAi->value, 'value' => '']]);

        expect(DB::table('jobs')->count())->toBe(0);
    });

    expect(DB::table('jobs')->count())->toBe(1);
});

it('dispatches the categorise job after commit', function () {
    expect(new CategoriseRuleMatchesJob($this->user, [1])->afterCommit)->toBeTrue();
});

it('carries the user attribution middleware', function () {
    $job = new CategoriseRuleMatchesJob($this->user, [1]);

    expect(collect($job->middleware())->contains(fn (object $m): bool => $m instanceof AttributeJobToUser))->toBeTrue();
});

it('hides the AI categorise action when the flag is off', function () {
    config(['budget.rule_ai_categorisation' => false]);
    $group = UserRuleGroup::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openAddRuleModal', $group->id)
        ->assertDontSee('Categorise with AI');

    config(['budget.rule_ai_categorisation' => true]);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openAddRuleModal', $group->id)
        ->assertSee('Categorise with AI');
});

it('keeps offering the AI action on a rule that already has it and refuses it on others', function () {
    $rule = aiRule($this->user);
    $group = UserRuleGroup::factory()->for($this->user)->create();
    config(['budget.rule_ai_categorisation' => false]);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openEditRuleModal', $rule->id)
        ->assertSee('Categorise with AI')
        ->call('saveRule')
        ->assertHasNoErrors();

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openAddRuleModal', $group->id)
        ->set('ruleName', 'Sneaky')
        ->set('triggers.0.field', 'description')
        ->set('triggers.0.operator', 'contains')
        ->set('triggers.0.value', 'X')
        ->set('actions.0.type', RuleActionType::SuggestCategoryWithAi->value)
        ->call('saveRule')
        ->assertHasErrors(['actions.0.type']);
});
