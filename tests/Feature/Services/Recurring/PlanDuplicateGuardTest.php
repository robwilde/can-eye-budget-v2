<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PlannedTransactionStatus;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Merchant;
use App\Models\MerchantAlias;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Recurring\PlanDuplicateGuard;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create([
        'description' => 'Rent',
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'merchant_key' => null,
    ]);
});

function duplicateExists(object $test, int $amount = 10000, ?int $accountId = null, TransactionDirection $direction = TransactionDirection::Debit, ?int $merchantId = null, string $description = 'Rent', ?User $user = null): bool
{
    return app(PlanDuplicateGuard::class)->exists($user ?? $test->user, $accountId ?? $test->account->id, $amount, $direction, $merchantId, $description);
}

it('matches the same description ignoring case and spacing', function () {
    expect(duplicateExists($this, description: '  RENT '))->toBeTrue();
});

it('matches the same merchant under another description', function () {
    $merchant = Merchant::factory()->for($this->user)->create();
    $this->plan->update(['merchant_key' => $merchant->merchant_key, 'description' => 'Landlord payment']);

    expect(duplicateExists($this, merchantId: $merchant->id, description: 'Something else'))->toBeTrue();
});

it('accepts a different amount, account, direction or description', function () {
    $other = Account::factory()->for($this->user)->create();

    expect(duplicateExists($this, amount: 6000))->toBeFalse()
        ->and(duplicateExists($this, accountId: $other->id))->toBeFalse()
        ->and(duplicateExists($this, direction: TransactionDirection::Credit))->toBeFalse()
        ->and(duplicateExists($this, description: 'Water'))->toBeFalse();
});

it('matches a stored amount whatever the sign of the one asked about', function () {
    expect(duplicateExists($this, amount: -10000))->toBeTrue();
});

it('matches an inactive plan', function () {
    $this->plan->update(['status' => PlannedTransactionStatus::Inactive]);

    expect(duplicateExists($this))->toBeTrue();
});

it('never matches without a description or merchant', function () {
    expect(duplicateExists($this, description: ' '))->toBeFalse();
});

it('ignores a transfer plan', function () {
    $this->plan->update(['transfer_to_account_id' => Account::factory()->for($this->user)->create()->id]);

    expect(duplicateExists($this))->toBeFalse();
});

it('ignores another users plan even on the same account id', function () {
    $other = User::factory()->create();

    expect(duplicateExists($this, user: $other))->toBeFalse();
});

it('matches a plan saved under the key of a merchant merged into the chosen one', function () {
    $kept = Merchant::factory()->for($this->user)->create();
    $merged = Merchant::factory()->for($this->user)->create();
    MerchantAlias::query()->create(['user_id' => $this->user->id, 'merchant_id' => $kept->id, 'merchant_key' => $merged->merchant_key]);
    $this->plan->update(['merchant_key' => $merged->merchant_key, 'description' => 'Old name']);

    expect(duplicateExists($this, merchantId: $kept->id, description: 'New name'))->toBeTrue();
});

it('ignores another users merchant id', function () {
    $foreign = Merchant::factory()->for(User::factory()->create())->create();
    $this->plan->update(['merchant_key' => $foreign->merchant_key, 'description' => 'Landlord']);

    expect(duplicateExists($this, merchantId: $foreign->id, description: ''))->toBeFalse();
});
