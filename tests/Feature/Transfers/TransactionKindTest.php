<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\NewTransfer;
use App\Enums\AccountClass;
use App\Enums\TransactionDirection;
use App\Enums\TransactionKind;
use App\Enums\TransferLinkSource;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transactions\TransactionDeleter;
use App\Services\Transfers\TransferKinds;
use App\Services\Transfers\TransferLinker;
use App\Services\UserCategoryProvisioner;
use App\Support\Transactions\TransactionTotals;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo('2026-10-15');
    $this->user = User::factory()->create();
    app(UserCategoryProvisioner::class)->provision($this->user);
    $this->checking = kindAccount($this->user, AccountClass::Transaction);
});

function kindAccount(User $user, AccountClass $type, bool $tracked = true): Account
{
    return Account::factory()->for($user)->create(['type' => $type, 'is_tracked' => $tracked]);
}

function kindRow(User $user, Account $account, int $cents, string $date = '2026-10-10', ?int $categoryId = null): Transaction
{
    return Transaction::factory()->for($user)->fromRedbark()->create([
        'account_id' => $account->id,
        'direction' => $cents < 0 ? TransactionDirection::Debit : TransactionDirection::Credit,
        'amount' => $cents,
        'post_date' => $date,
        'description' => 'Transfer',
        'category_id' => $categoryId,
    ]);
}

function kindPair(User $user, Account $from, Account $to, TransferLinkSource $source = TransferLinkSource::Confirmed, ?int $categoryId = null): array
{
    $out = kindRow($user, $from, -50000, '2026-10-10', $categoryId);
    $in = kindRow($user, $to, 50000, '2026-10-12');

    expect(app(TransferLinker::class)->link($out, $in, $source))->toBeTrue();

    return [$out->fresh(), $in->fresh()];
}

it('derives the payment kind on confirm and not on suggestion', function () {
    $card = kindAccount($this->user, AccountClass::CreditCard);
    $out = kindRow($this->user, $this->checking, -50000);
    $in = kindRow($this->user, $card, 50000, '2026-10-12');

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($out->fresh()->suggested_pair_id)->toBe($in->id)
        ->and($out->fresh()->kind)->toBe(TransactionKind::Standard)
        ->and($in->fresh()->kind)->toBe(TransactionKind::Standard)
        ->and($out->fresh()->category_id)->toBeNull();

    expect(app(TransferLinker::class)->confirm($out->fresh()))->toBeTrue();

    expect($out->fresh()->kind)->toBe(TransactionKind::CreditCardPayment)
        ->and($in->fresh()->kind)->toBe(TransactionKind::FundsMovement);
});

it('changes no kind or category when a suggestion is rejected', function () {
    $loan = kindAccount($this->user, AccountClass::Loan);
    $out = kindRow($this->user, $this->checking, -50000);
    $in = kindRow($this->user, $loan, 50000, '2026-10-12');

    $this->artisan('transfers:detect')->assertSuccessful();
    app(TransferLinker::class)->markNotTransfer($out->fresh());

    expect($out->fresh()->kind)->toBe(TransactionKind::Standard)
        ->and($in->fresh()->kind)->toBe(TransactionKind::Standard);
});

it('never counts a tracked to tracked payment as spend', function (AccountClass $destination) {
    $target = kindAccount($this->user, $destination);
    Transaction::factory()->for($this->user)->manual()->create([
        'account_id' => $this->checking->id, 'direction' => TransactionDirection::Debit, 'amount' => 1200,
        'post_date' => '2026-10-05', 'description' => 'LUNCH', 'transfer_pair_id' => null,
    ]);
    [$out] = kindPair($this->user, $this->checking, $target);

    $totals = TransactionTotals::summarise(Transaction::query()->where('user_id', $this->user->id)->current(), $this->user->id);

    expect($out->kind)->not->toBe(TransactionKind::Standard)
        ->and($totals['expenses'])->toBe(1200)
        ->and(Transaction::query()->where('user_id', $this->user->id)->countable()->where('direction', TransactionDirection::Debit)->sum('amount'))->toEqual(1200);
})->with([
    'loan' => [AccountClass::Loan],
    'mortgage' => [AccountClass::Mortgage],
    'credit card' => [AccountClass::CreditCard],
    'investment' => [AccountClass::Investment],
]);

it('categorises a confirmed investment contribution', function () {
    $investment = kindAccount($this->user, AccountClass::Investment);

    [$out, $in] = kindPair($this->user, $this->checking, $investment);

    $category = Category::query()->where('user_id', $this->user->id)->where('name', TransferKinds::INVESTMENT_CATEGORY)->sole();

    expect($out->kind)->toBe(TransactionKind::InvestmentContribution)
        ->and($out->category_id)->toBe($category->id)
        ->and($category->parent_id)->toBeNull()
        ->and($category->ownGroup->name)->toBe(UserCategoryProvisioner::SAVINGS_GROUP)
        ->and($in->kind)->toBe(TransactionKind::FundsMovement)
        ->and($in->category_id)->toBeNull();
});

it('keeps a category the outflow already has when it is an investment contribution', function () {
    $investment = kindAccount($this->user, AccountClass::Investment);
    $custom = Category::factory()->for($this->user)->create(['name' => 'Shares']);

    [$out] = kindPair($this->user, $this->checking, $investment, categoryId: $custom->id);

    expect($out->kind)->toBe(TransactionKind::InvestmentContribution)
        ->and($out->category_id)->toBe($custom->id)
        ->and(Category::query()->where('user_id', $this->user->id)->where('name', TransferKinds::INVESTMENT_CATEGORY)->exists())->toBeFalse();
});

it('finds or creates the investment category per user and never reuses another users', function () {
    $investment = kindAccount($this->user, AccountClass::Investment);
    $stranger = User::factory()->create();
    $strangerCategory = Category::factory()->for($stranger)->create(['name' => TransferKinds::INVESTMENT_CATEGORY]);

    [$first] = kindPair($this->user, $this->checking, $investment);
    [$second] = kindPair($this->user, $this->checking, $investment);

    expect($second->category_id)->toBe($first->category_id)
        ->and($first->category_id)->not->toBe($strangerCategory->id)
        ->and(Category::query()->where('user_id', $this->user->id)->where('name', TransferKinds::INVESTMENT_CATEGORY)->count())->toBe(1);
});

it('derives funds movement between two investment accounts', function () {
    $source = kindAccount($this->user, AccountClass::Investment);
    $destination = kindAccount($this->user, AccountClass::Investment);

    [$out, $in] = kindPair($this->user, $source, $destination);

    expect($out->kind)->toBe(TransactionKind::FundsMovement)
        ->and($out->category_id)->toBeNull()
        ->and($in->kind)->toBe(TransactionKind::FundsMovement);
});

it('derives a loan payment for a loan or mortgage destination', function (AccountClass $destination) {
    [$out, $in] = kindPair($this->user, $this->checking, kindAccount($this->user, $destination));

    expect($out->kind)->toBe(TransactionKind::LoanPayment)
        ->and($in->kind)->toBe(TransactionKind::FundsMovement);
})->with(['loan' => [AccountClass::Loan], 'mortgage' => [AccountClass::Mortgage]]);

it('derives funds movement for any other destination', function (AccountClass $destination) {
    [$out, $in] = kindPair($this->user, $this->checking, kindAccount($this->user, $destination));

    expect($out->kind)->toBe(TransactionKind::FundsMovement)
        ->and($in->kind)->toBe(TransactionKind::FundsMovement);
})->with(['savings' => [AccountClass::Savings], 'transaction' => [AccountClass::Transaction], 'term deposit' => [AccountClass::TermDeposit]]);

it('derives the kind on a manual link and a rule link', function (TransferLinkSource $source) {
    [$out, $in] = kindPair($this->user, $this->checking, kindAccount($this->user, AccountClass::CreditCard), $source);

    expect($out->kind)->toBe(TransactionKind::CreditCardPayment)
        ->and($in->kind)->toBe(TransactionKind::FundsMovement);
})->with(['manual' => [TransferLinkSource::Manual], 'rule' => [TransferLinkSource::Rule]]);

it('derives the kind for a transfer created in the modal', function () {
    $card = kindAccount($this->user, AccountClass::CreditCard);

    $debit = app(TransferLinker::class)->create(new NewTransfer(
        userId: $this->user->id, fromAccountId: $this->checking->id, toAccountId: $card->id,
        amount: 50000, description: 'pay card', postDate: '2026-10-10',
    ));

    expect($debit->fresh()->kind)->toBe(TransactionKind::CreditCardPayment)
        ->and($debit->transferPair->kind)->toBe(TransactionKind::FundsMovement);
});

it('derives the kind when a mirror leg is created on a hidden loan account', function () {
    $hidden = kindAccount($this->user, AccountClass::Loan, tracked: false);
    $out = kindRow($this->user, $this->checking, -50000);

    $mirror = app(TransferLinker::class)->linkToUntrackedAccount($out, $hidden, TransferLinkSource::Manual);

    expect($out->fresh()->kind)->toBe(TransactionKind::LoanPayment)
        ->and($mirror->fresh()->kind)->toBe(TransactionKind::FundsMovement);
});

it('resets kinds when a transfer is unmatched', function () {
    [$out, $in] = kindPair($this->user, $this->checking, kindAccount($this->user, AccountClass::CreditCard));

    app(TransferLinker::class)->unlink($out);

    expect($out->fresh()->kind)->toBe(TransactionKind::Standard)
        ->and($in->fresh()->kind)->toBe(TransactionKind::Standard);
});

it('resets the surviving leg when one transfer leg is deleted', function () {
    [$out, $in] = kindPair($this->user, $this->checking, kindAccount($this->user, AccountClass::Loan));
    $out->forceFill(['source' => App\Enums\TransactionSource::Manual])->save();
    $in->forceFill(['source' => App\Enums\TransactionSource::Manual])->save();

    app(TransactionDeleter::class)->delete(Transaction::query()->where('user_id', $this->user->id)->whereKey($out->id));

    expect($in->fresh()->kind)->toBe(TransactionKind::Standard)
        ->and($in->fresh()->transfer_pair_id)->toBeNull();
});

it('keeps the kind on a new version of a transaction', function () {
    $transaction = kindRow($this->user, $this->checking, -2500);
    $transaction->forceFill(['kind' => TransactionKind::OneTime])->save();

    $child = $transaction->fresh()->createChild(['notes' => 'edited']);

    expect($child->fresh()->kind)->toBe(TransactionKind::OneTime);
});

it('defaults a new transaction to standard', function () {
    expect(kindRow($this->user, $this->checking, -100)->kind)->toBe(TransactionKind::Standard)
        ->and(Transaction::query()->where('user_id', $this->user->id)->sole()->kind)->toBe(TransactionKind::Standard);
});

it('keeps the automatic investment category on the outflow and leaves its plan and siblings alone', function () {
    $investment = kindAccount($this->user, AccountClass::Investment);
    $custom = Category::factory()->for($this->user)->create(['name' => 'Rent share']);
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->checking)->monthly()->create([
        'direction' => TransactionDirection::Debit, 'amount' => 50000, 'description' => 'invest plan',
        'start_date' => '2026-05-01', 'category_id' => $custom->id,
    ]);
    $sibling = kindRow($this->user, $this->checking, -1000, '2026-09-10', $custom->id);
    $sibling->forceFill(['planned_transaction_id' => $planned->id])->save();
    $out = kindRow($this->user, $this->checking, -50000);
    $out->forceFill(['planned_transaction_id' => $planned->id])->save();
    $in = kindRow($this->user, $investment, 50000, '2026-10-12');

    expect(app(TransferLinker::class)->link($out->fresh(), $in, TransferLinkSource::Confirmed))->toBeTrue();

    $investmentCategory = Category::query()->where('user_id', $this->user->id)->where('name', TransferKinds::INVESTMENT_CATEGORY)->sole();

    expect($out->fresh()->category_id)->toBe($investmentCategory->id)
        ->and($sibling->fresh()->category_id)->toBe($custom->id)
        ->and($planned->fresh()->category_id)->toBe($custom->id);
});

it('uses a category a competing first use created while the owner was locked', function () {
    $investment = kindAccount($this->user, AccountClass::Investment);
    $competing = null;

    DB::listen(function ($query) use (&$competing): void {
        if ($competing === null && str_starts_with($query->sql, 'select "id" from "users"')) {
            $competing = Category::query()->create(['user_id' => $this->user->id, 'name' => TransferKinds::INVESTMENT_CATEGORY]);
        }
    });

    [$out] = kindPair($this->user, $this->checking, $investment);

    expect($competing)->not->toBeNull()
        ->and($out->category_id)->toBe($competing->id)
        ->and(Category::query()->where('user_id', $this->user->id)->where('name', TransferKinds::INVESTMENT_CATEGORY)->count())->toBe(1);
});
