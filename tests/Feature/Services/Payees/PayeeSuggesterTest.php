<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BudgetTag;
use App\Enums\CategorySource;
use App\Enums\PayeeStatus;
use App\Exceptions\TypeSafe\TypeSafeException;
use App\Models\Account;
use App\Models\Category;
use App\Models\MerchantBrand;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\Payees\PayeeSuggester;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config(['services.typesafe.api_key' => 'ts_test']);
    Sleep::fake();

    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();

    $this->groceries = Category::factory()->create(['name' => 'Groceries', 'parent_id' => null, 'budget_tag' => BudgetTag::Needs]);
    $this->eatingOut = Category::factory()->create(['name' => 'Eating Out', 'parent_id' => null, 'budget_tag' => BudgetTag::Wants]);
    $this->restaurant = Category::factory()->create(['name' => 'Restaurant', 'parent_id' => $this->eatingOut->id]);
    $this->quickFoods = Category::factory()->create(['name' => 'Quick Foods', 'parent_id' => $this->eatingOut->id]);
    $this->income = Category::factory()->create(['name' => 'Income', 'parent_id' => null, 'budget_tag' => null]);
});

function payeeRow(object $test, string $merchant, array $overrides = []): Transaction
{
    return Transaction::factory()->for($test->user)->for($test->account)->debit()->create([
        'description' => 'CARD 4455 '.$merchant.'  REF 998877',
        'merchant_name' => $merchant,
        'amount' => 12345,
        'category_id' => null,
        'category_source' => null,
        ...$overrides,
    ]);
}

/**
 * Fake Jev: answer every question with the option on the path to $answers[masked merchant name]
 * (a category full path), giving it $confidence and spreading the rest evenly.
 *
 * @param  array<string, string>  $answers
 */
function payeeJev(array $answers, float $confidence = 0.95): void
{
    Http::fake(function (Request $request) use ($answers, $confidence) {
        $target = $answers[$request['state']['merchant_name']] ?? '';
        $criteria = $request['questions']['answer']['criteria'];

        $choice = array_key_first($criteria);
        foreach ($criteria as $id => $path) {
            if ($target === $path || str_starts_with($target, $path.' / ')) {
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

/** @return list<array<string, mixed>> */
function payeeSentStates(): array
{
    return Http::recorded()->map(fn (array $pair): array => $pair[0]['state'])->values()->all();
}

function payeeNeighbours(object $test, string $industry, array $categoryIds): void
{
    foreach ($categoryIds as $index => $categoryId) {
        $key = 'neighbour '.$index;

        MerchantBrand::factory()->create(['user_id' => $test->user->id, 'merchant_key' => $key, 'industry' => $industry, 'subindustry' => 'Cafes']);
        Payee::factory()->create([
            'user_id' => $test->user->id,
            'merchant_key' => $key,
            'status' => PayeeStatus::Confirmed,
            'confirmed_category_id' => $categoryId,
        ]);
    }
}

test('a confident suggestion is applied to uncategorised rows as Suggested', function () {
    payeeJev(['ALDI #' => 'Groceries']);
    $first = payeeRow($this, 'ALDI 123');
    $second = payeeRow($this, 'ALDI 456');

    $count = app(PayeeSuggester::class)->suggestForUser($this->user);

    $payee = Payee::query()->where('user_id', $this->user->id)->sole();

    expect($count)->toBe(1)
        ->and($payee->status)->toBe(PayeeStatus::Pending)
        ->and($payee->suggested_category_id)->toBe($this->groceries->id)
        ->and($payee->auto_applied)->toBeTrue()
        ->and($payee->confidence)->toBeGreaterThanOrEqual(PayeeSuggester::AUTO_APPLY_CONFIDENCE)
        ->and($first->fresh()->category_id)->toBe($this->groceries->id)
        ->and($first->fresh()->category_source)->toBe(CategorySource::Suggested)
        ->and($second->fresh()->category_source)->toBe(CategorySource::Suggested);
});

test('a suggestion never overwrites a category the user set by hand', function () {
    payeeJev(['ALDI #' => 'Groceries']);
    $uncategorised = payeeRow($this, 'ALDI 123');
    $manual = payeeRow($this, 'ALDI 123', ['category_id' => $this->eatingOut->id, 'category_source' => CategorySource::Manual]);
    $byRule = payeeRow($this, 'ALDI 123', ['category_id' => $this->quickFoods->id, 'category_source' => CategorySource::Rule]);

    app(PayeeSuggester::class)->suggestForUser($this->user);

    expect($uncategorised->fresh()->category_id)->toBe($this->groceries->id)
        ->and($manual->fresh()->category_id)->toBe($this->eatingOut->id)
        ->and($manual->fresh()->category_source)->toBe(CategorySource::Manual)
        ->and($byRule->fresh()->category_id)->toBe($this->quickFoods->id)
        ->and($byRule->fresh()->category_source)->toBe(CategorySource::Rule);
});

test('a low confidence suggestion leaves the rows uncategorised for review', function () {
    payeeJev(['ALDI #' => 'Groceries'], confidence: 0.5);
    $row = payeeRow($this, 'ALDI 123');

    app(PayeeSuggester::class)->suggestForUser($this->user);

    $payee = Payee::query()->where('user_id', $this->user->id)->sole();

    expect($payee->status)->toBe(PayeeStatus::Pending)
        ->and($payee->suggested_category_id)->toBe($this->groceries->id)
        ->and($payee->auto_applied)->toBeFalse()
        ->and($row->fresh()->category_id)->toBeNull()
        ->and($row->fresh()->category_source)->toBeNull();
});

test('a root with several categories is resolved in two steps down to the chosen category', function () {
    payeeJev(['CAFE #' => 'Eating Out / Quick Foods']);
    $row = payeeRow($this, 'CAFE 77');

    app(PayeeSuggester::class)->suggestForUser($this->user);

    expect(Http::recorded())->toHaveCount(2)
        ->and($row->fresh()->category_id)->toBe($this->quickFoods->id);
});

test('a root without children is answered in one step', function () {
    payeeJev(['ALDI #' => 'Groceries']);
    payeeRow($this, 'ALDI 123');

    app(PayeeSuggester::class)->suggestForUser($this->user);

    expect(Http::recorded())->toHaveCount(1);
});

test('only roots carrying a budget tag are offered at the first step', function () {
    payeeJev(['ALDI #' => 'Groceries']);
    payeeRow($this, 'ALDI 123');

    app(PayeeSuggester::class)->suggestForUser($this->user);

    $criteria = Http::recorded()->first()[0]['questions']['answer']['criteria'];

    expect(array_values($criteria))->toEqualCanonicalizing(['Groceries', 'Eating Out'])
        ->and($criteria)->not->toHaveKey('c'.$this->income->id);
});

test('only the digit-masked merchant name is sent to Jev', function () {
    payeeJev(['ALDI #' => 'Groceries', 'CAFE #' => 'Eating Out']);
    payeeRow($this, 'ALDI 123');
    payeeRow($this, 'CAFE 77');

    app(PayeeSuggester::class)->suggestForUser($this->user);

    $bodies = Http::recorded()->map(fn (array $pair): string => json_encode($pair[0]->data(), JSON_THROW_ON_ERROR));
    $states = payeeSentStates();

    expect(collect($states)->every(fn (array $state): bool => array_keys($state) === ['merchant_name']))->toBeTrue()
        ->and(collect($states)->pluck('merchant_name')->unique()->sort()->values()->all())->toBe(['ALDI #', 'CAFE #'])
        ->and($bodies->contains(fn (string $body): bool => str_contains($body, '998877') || str_contains($body, '12345') || str_contains($body, $this->user->email) || str_contains($body, '4455')))->toBeFalse()
        ->and($bodies->contains(fn (string $body): bool => str_contains($body, 'ALDI') && str_contains($body, 'CAFE')))->toBeFalse();
});

test('payees that already exist are never sent to Jev again', function (PayeeStatus $status) {
    Http::fake();
    $row = payeeRow($this, 'ALDI 123');
    Payee::factory()->create([
        'user_id' => $this->user->id,
        'merchant_key' => $row->merchant_key,
        'status' => $status,
    ]);

    $count = app(PayeeSuggester::class)->suggestForUser($this->user);

    expect($count)->toBe(0)
        ->and(app(PayeeSuggester::class)->suggest($this->user, $row))->toBeNull()
        ->and($row->fresh()->category_id)->toBeNull();
    Http::assertNothingSent();
})->with([
    'pending' => PayeeStatus::Pending,
    'confirmed' => PayeeStatus::Confirmed,
    'dismissed' => PayeeStatus::Dismissed,
]);

test('a merchant an active rule already categorises is not sent to Jev', function () {
    Http::fake();
    $row = payeeRow($this, 'ALDI 123');
    UserRule::factory()->for($this->user)->create([
        'triggers' => [['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'ALDI']],
        'actions' => [['type' => 'set_category', 'value' => (string) $this->groceries->id]],
    ]);

    $count = app(PayeeSuggester::class)->suggestForUser($this->user);

    expect($count)->toBe(0)
        ->and(Payee::query()->count())->toBe(0)
        ->and($row->fresh()->category_id)->toBeNull();
    Http::assertNothingSent();
});

test('a matching rule in an inactive group does not stop a merchant being asked about', function () {
    payeeJev(['ALDI #' => 'Groceries']);
    payeeRow($this, 'ALDI 123');
    UserRule::factory()->for($this->user)->for(UserRuleGroup::factory()->for($this->user)->inactive(), 'group')->create([
        'triggers' => [['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'ALDI']],
        'actions' => [['type' => 'set_category', 'value' => (string) $this->groceries->id]],
    ]);

    expect(app(PayeeSuggester::class)->suggestForUser($this->user))->toBe(1)
        ->and(Payee::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

test('credits, transfers, split rows and rows without a merchant are not asked about', function () {
    Http::fake();
    Transaction::factory()->for($this->user)->for($this->account)->credit()->create(['merchant_name' => 'REFUND SHOP', 'category_id' => null, 'category_source' => null]);
    Transaction::factory()->for($this->user)->for($this->account)->debit()->transfer()->create(['merchant_name' => 'TRANSFER SHOP', 'category_id' => null, 'category_source' => null]);
    $split = payeeRow($this, 'SPLIT SHOP');
    TransactionSplit::factory()->create(['transaction_id' => $split->id, 'category_id' => $this->groceries->id]);
    payeeRow($this, '', ['description' => 'NO MERCHANT  REF 1']);

    expect(app(PayeeSuggester::class)->hasCandidates($this->user))->toBeFalse()
        ->and(app(PayeeSuggester::class)->suggestForUser($this->user))->toBe(0);
    Http::assertNothingSent();
});

test("another user's rows and payees are never touched", function () {
    payeeJev(['ALDI #' => 'Groceries']);
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->create();
    $otherRow = Transaction::factory()->for($other)->for($otherAccount)->debit()->create([
        'description' => 'CARD 4455 ALDI 123  REF 1',
        'merchant_name' => 'ALDI 123',
        'category_id' => null,
        'category_source' => null,
    ]);
    $mine = payeeRow($this, 'ALDI 123');

    expect(app(PayeeSuggester::class)->suggest($this->user, $otherRow))->toBeNull();

    app(PayeeSuggester::class)->suggestForUser($this->user);

    expect($otherRow->fresh()->category_id)->toBeNull()
        ->and(Payee::query()->where('user_id', $other->id)->count())->toBe(0)
        ->and($mine->fresh()->category_id)->toBe($this->groceries->id);
});

test('suggest returns the stored payee for one transaction', function () {
    payeeJev(['ALDI #' => 'Groceries']);
    $row = payeeRow($this, 'ALDI 123');

    $payee = app(PayeeSuggester::class)->suggest($this->user, $row);

    expect($payee)->toBeInstanceOf(Payee::class)
        ->and($payee->merchant_key)->toBe($row->merchant_key)
        ->and($payee->merchant_name)->toBe('ALDI 123');
});

test('a Jev failure raises and stores no payee', function () {
    Http::fake(['*' => Http::response([], 500)]);
    payeeRow($this, 'ALDI 123');

    expect(fn () => app(PayeeSuggester::class)->suggestForUser($this->user))->toThrow(TypeSafeException::class)
        ->and(Payee::query()->count())->toBe(0);
});

test('the hint is sent when at least two similar confirmed payees agree', function (array $neighbourCategories, bool $hinted) {
    payeeJev(['BREW HOUSE' => 'Eating Out']);
    $row = payeeRow($this, 'BREW HOUSE');
    MerchantBrand::factory()->create(['user_id' => $this->user->id, 'merchant_key' => $row->merchant_key, 'industry' => 'Food', 'subindustry' => 'Cafes']);
    payeeNeighbours($this, 'Food', array_map(fn (string $name): int => $this->{$name}->id, $neighbourCategories));

    app(PayeeSuggester::class)->suggestForUser($this->user);

    $state = payeeSentStates()[0];

    expect(array_key_exists('user_usually_categorises_similar_payees_as', $state))->toBe($hinted);
    if ($hinted) {
        expect($state['user_usually_categorises_similar_payees_as'])->toBe($this->eatingOut->fullPath());
    }
})->with([
    'one neighbour is not enough' => [['eatingOut'], false],
    'two neighbours that disagree' => [['eatingOut', 'groceries'], false],
    'two neighbours that agree' => [['eatingOut', 'eatingOut'], true],
    'two of three agree' => [['eatingOut', 'eatingOut', 'groceries'], true],
    'one of three agrees' => [['eatingOut', 'groceries', 'income'], false],
]);

test('neighbours in a different industry or belonging to another user give no hint', function () {
    payeeJev(['BREW HOUSE' => 'Eating Out']);
    $row = payeeRow($this, 'BREW HOUSE');
    MerchantBrand::factory()->create(['user_id' => $this->user->id, 'merchant_key' => $row->merchant_key, 'industry' => 'Food', 'subindustry' => 'Cafes']);
    payeeNeighbours($this, 'Hardware', [$this->eatingOut->id, $this->eatingOut->id]);

    $other = User::factory()->create();
    foreach (['theirs 1', 'theirs 2'] as $key) {
        MerchantBrand::factory()->create(['user_id' => $other->id, 'merchant_key' => $key, 'industry' => 'Food', 'subindustry' => 'Cafes']);
        Payee::factory()->create(['user_id' => $other->id, 'merchant_key' => $key, 'status' => PayeeStatus::Confirmed, 'confirmed_category_id' => $this->eatingOut->id]);
    }

    app(PayeeSuggester::class)->suggestForUser($this->user);

    expect(payeeSentStates()[0])->not->toHaveKey('user_usually_categorises_similar_payees_as');
});
