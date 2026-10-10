<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BudgetTag;
use App\Enums\CategorySource;
use App\Enums\PayeeStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Models\UserRule;
use App\Services\Payees\PayeeConfirmer;
use App\Services\Payees\PayeeSuggester;
use App\Services\RuleEvaluator;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.typesafe.api_key' => 'ts_test']);

    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();

    $this->eatingOut = Category::factory()->create(['name' => 'Eating Out', 'parent_id' => null, 'budget_tag' => BudgetTag::Wants]);
    $this->groceries = Category::factory()->create(['name' => 'Groceries', 'parent_id' => null, 'budget_tag' => BudgetTag::Needs]);
    $this->hidden = Category::factory()->create(['name' => 'Hidden', 'parent_id' => null, 'is_hidden' => true]);

    $this->payee = Payee::factory()->create([
        'user_id' => $this->user->id,
        'merchant_key' => confirmerKey('BREW HOUSE'),
        'merchant_name' => 'BREW HOUSE',
        'suggested_category_id' => $this->eatingOut->id,
        'confidence' => 0.6,
        'top_to_second' => 1.5,
    ]);
});

function confirmerKey(string $merchantName): string
{
    return (new Transaction(['merchant_name' => $merchantName]))->resolveMerchantKey();
}

function confirmerRow(object $test, array $overrides = []): Transaction
{
    return Transaction::factory()->for($test->user)->for($test->account)->debit()->create([
        'description' => 'VISA - BREW HOUSE  BRISBANE AU',
        'merchant_name' => 'BREW HOUSE',
        'category_id' => null,
        'category_source' => null,
        ...$overrides,
    ]);
}

test('confirming files exactly one rule, moves the history and records the answer', function () {
    $uncategorised = confirmerRow($this);
    $suggested = confirmerRow($this, ['category_id' => $this->groceries->id, 'category_source' => CategorySource::Suggested]);
    $byRule = confirmerRow($this, ['category_id' => $this->groceries->id, 'category_source' => CategorySource::Rule]);

    $payee = app(PayeeConfirmer::class)->confirm($this->user, $this->payee, $this->eatingOut->id);

    $rule = UserRule::query()->where('user_id', $this->user->id)->sole();

    expect($payee->status)->toBe(PayeeStatus::Confirmed)
        ->and($payee->confirmed_category_id)->toBe($this->eatingOut->id)
        ->and($payee->user_rule_id)->toBe($rule->id)
        ->and($payee->resolved_at)->not->toBeNull()
        ->and($rule->group->name)->toBe('Auto-categorisation')
        ->and($rule->actions)->toBe([['type' => 'set_category', 'value' => (string) $this->eatingOut->id]])
        ->and($rule->is_active)->toBeTrue();

    foreach ([$uncategorised, $suggested, $byRule] as $row) {
        expect($row->fresh()->category_id)->toBe($this->eatingOut->id)
            ->and($row->fresh()->category_source)->toBe(CategorySource::Manual);
    }
});

test('the rule recognises later purchases from the merchant but not credits', function () {
    confirmerRow($this);
    $rule = UserRule::query()->findOrFail(app(PayeeConfirmer::class)->confirm($this->user, $this->payee, $this->eatingOut->id)->user_rule_id);

    $later = confirmerRow($this);
    $refund = Transaction::factory()->for($this->user)->for($this->account)->credit()->create(['description' => 'VISA - BREW HOUSE  BRISBANE AU', 'merchant_name' => 'BREW HOUSE']);

    expect(app(RuleEvaluator::class)->matches($later, $rule))->toBeTrue()
        ->and(app(RuleEvaluator::class)->matches($refund, $rule))->toBeFalse();
});

test('the rule is keyed to the payee so a later purchase with a different description still matches', function () {
    confirmerRow($this);
    $rule = UserRule::query()->findOrFail(app(PayeeConfirmer::class)->confirm($this->user, $this->payee, $this->eatingOut->id)->user_rule_id);

    $reworded = confirmerRow($this, ['description' => 'EFTPOS 0042 SOMETHING ELSE ENTIRELY']);
    $otherMerchant = confirmerRow($this, ['description' => 'VISA - BREW HOUSE  BRISBANE AU', 'merchant_name' => 'DIFFERENT SHOP']);

    expect($rule->triggers[0])->toBe(['field' => 'merchant_key', 'operator' => 'equals', 'value' => $this->payee->merchant_key])
        ->and(app(RuleEvaluator::class)->matches($reworded, $rule))->toBeTrue()
        ->and(app(RuleEvaluator::class)->matches($otherMerchant, $rule))->toBeFalse();
});

test('a confirmed payee is never sent to Jev again', function () {
    Http::fake();
    confirmerRow($this);

    app(PayeeConfirmer::class)->confirm($this->user, $this->payee, $this->eatingOut->id);
    confirmerRow($this, ['description' => 'VISA - BREW HOUSE  SYDNEY AU']);

    expect(app(PayeeSuggester::class)->suggestForUser($this->user))->toBe(0);
    Http::assertNothingSent();
});

test('confirming twice is idempotent and a changed answer updates the same rule', function () {
    $row = confirmerRow($this);

    app(PayeeConfirmer::class)->confirm($this->user, $this->payee, $this->eatingOut->id);
    app(PayeeConfirmer::class)->confirm($this->user, $this->payee->fresh(), $this->eatingOut->id);

    expect(UserRule::query()->where('user_id', $this->user->id)->count())->toBe(1);

    $payee = app(PayeeConfirmer::class)->confirm($this->user, $this->payee->fresh(), $this->groceries->id);

    $rule = UserRule::query()->where('user_id', $this->user->id)->sole();

    expect($rule->id)->toBe($payee->user_rule_id)
        ->and($rule->actions)->toBe([['type' => 'set_category', 'value' => (string) $this->groceries->id]])
        ->and($row->fresh()->category_id)->toBe($this->eatingOut->id);
});

test('rows the user categorised by hand are left where they put them', function () {
    $manual = confirmerRow($this, ['category_id' => $this->groceries->id, 'category_source' => CategorySource::Manual]);
    $open = confirmerRow($this);

    app(PayeeConfirmer::class)->confirm($this->user, $this->payee, $this->eatingOut->id);

    expect($manual->fresh()->category_id)->toBe($this->groceries->id)
        ->and($open->fresh()->category_id)->toBe($this->eatingOut->id);
});

test('credits, transfers, split rows and other merchants keep their category', function () {
    $credit = Transaction::factory()->for($this->user)->for($this->account)->credit()->create(['merchant_name' => 'BREW HOUSE', 'category_id' => null, 'category_source' => null]);
    $transfer = confirmerRow($this, ['transfer_pair_id' => Transaction::factory()->create()->id]);
    $split = confirmerRow($this);
    TransactionSplit::factory()->create(['transaction_id' => $split->id, 'category_id' => $this->groceries->id]);
    $other = confirmerRow($this, ['merchant_name' => 'ALDI']);

    app(PayeeConfirmer::class)->confirm($this->user, $this->payee, $this->eatingOut->id);

    foreach ([$credit, $transfer, $split, $other] as $row) {
        expect($row->fresh()->category_id)->toBeNull();
    }
});

test("another user's history and rules are never touched", function () {
    $other = User::factory()->create();
    $otherRow = Transaction::factory()->for($other)->for(Account::factory()->for($other)->create())->debit()->create([
        'merchant_name' => 'BREW HOUSE',
        'category_id' => null,
        'category_source' => null,
    ]);
    confirmerRow($this);

    app(PayeeConfirmer::class)->confirm($this->user, $this->payee, $this->eatingOut->id);

    expect($otherRow->fresh()->category_id)->toBeNull()
        ->and(UserRule::query()->where('user_id', $other->id)->count())->toBe(0);
});

test('a payee that belongs to another user cannot be confirmed or dismissed', function () {
    $intruder = User::factory()->create();

    expect(fn () => app(PayeeConfirmer::class)->confirm($intruder, $this->payee, $this->eatingOut->id))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class)
        ->and(fn () => app(PayeeConfirmer::class)->dismiss($intruder, $this->payee))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class)
        ->and($this->payee->fresh()->status)->toBe(PayeeStatus::Pending)
        ->and(UserRule::query()->count())->toBe(0);
});

test('a hidden category is refused and nothing changes', function () {
    $row = confirmerRow($this);

    expect(fn () => app(PayeeConfirmer::class)->confirm($this->user, $this->payee, $this->hidden->id))
        ->toThrow(InvalidArgumentException::class)
        ->and($this->payee->fresh()->status)->toBe(PayeeStatus::Pending)
        ->and($row->fresh()->category_id)->toBeNull()
        ->and(UserRule::query()->count())->toBe(0);
});

test('a tag override is kept on the payee and absent when not given', function () {
    $payee = app(PayeeConfirmer::class)->confirm($this->user, $this->payee, $this->eatingOut->id, BudgetTag::Needs);

    expect($payee->budget_tag)->toBe(BudgetTag::Needs);

    $again = app(PayeeConfirmer::class)->confirm($this->user, $payee->fresh(), $this->eatingOut->id);

    expect($again->budget_tag)->toBeNull();
});

test('dismissing resolves the payee without a rule or any category change', function () {
    Http::fake();
    $row = confirmerRow($this);

    $payee = app(PayeeConfirmer::class)->dismiss($this->user, $this->payee);

    expect($payee->status)->toBe(PayeeStatus::Dismissed)
        ->and($payee->resolved_at)->not->toBeNull()
        ->and($row->fresh()->category_id)->toBeNull()
        ->and(UserRule::query()->count())->toBe(0)
        ->and(app(PayeeSuggester::class)->suggestForUser($this->user))->toBe(0);
    Http::assertNothingSent();
});

test('confirmMany accepts the suggestion of each of the users pending payees that has one', function () {
    $row = confirmerRow($this);
    $withoutSuggestion = Payee::factory()->create(['user_id' => $this->user->id, 'suggested_category_id' => null]);
    $alreadyConfirmed = Payee::factory()->create(['user_id' => $this->user->id, 'status' => PayeeStatus::Confirmed, 'suggested_category_id' => $this->groceries->id]);
    $foreign = Payee::factory()->create(['suggested_category_id' => $this->groceries->id]);
    $second = Payee::factory()->create(['user_id' => $this->user->id, 'merchant_key' => confirmerKey('ALDI'), 'merchant_name' => 'ALDI', 'suggested_category_id' => $this->groceries->id]);

    $confirmed = app(PayeeConfirmer::class)->confirmMany($this->user, [
        $this->payee->id, $second->id, $withoutSuggestion->id, $alreadyConfirmed->id, $foreign->id,
    ]);

    expect($confirmed)->toBe(2)
        ->and($this->payee->fresh()->status)->toBe(PayeeStatus::Confirmed)
        ->and($second->fresh()->confirmed_category_id)->toBe($this->groceries->id)
        ->and($withoutSuggestion->fresh()->status)->toBe(PayeeStatus::Pending)
        ->and($foreign->fresh()->status)->toBe(PayeeStatus::Pending)
        ->and($row->fresh()->category_id)->toBe($this->eatingOut->id)
        ->and(UserRule::query()->where('user_id', $this->user->id)->count())->toBe(2);
});
