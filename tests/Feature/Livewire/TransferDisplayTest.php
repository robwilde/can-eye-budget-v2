<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Enums\TransferLinkSource;
use App\Livewire\TransactionList;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transfers\TransferLinker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15'));
});

function displayFeedRow(User $user, Account $account, int $cents, string $description): Transaction
{
    return Transaction::factory()->for($user)->fromRedbark()->create([
        'account_id' => $account->id,
        'direction' => $cents < 0 ? TransactionDirection::Debit : TransactionDirection::Credit,
        'amount' => $cents,
        'post_date' => '2026-09-14',
        'description' => $description,
    ]);
}

test('a transfer to an untracked account reads Transfer → name on the debit side and ← on the credit side', function () {
    $user = User::factory()->create();
    $main = Account::factory()->for($user)->create();
    $spaceship = Account::factory()->for($user)->untracked()->create(['name' => 'Spaceship']);
    $out = displayFeedRow($user, $main, -5000, 'Moved to invest');
    $in = displayFeedRow($user, $main, 7000, 'Moved back');

    $linker = app(TransferLinker::class);
    $linker->linkToUntrackedAccount($out, $spaceship, TransferLinkSource::Manual);
    $linker->linkToUntrackedAccount($in, $spaceship, TransferLinkSource::Manual);

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSee('Transfer → Spaceship')
        ->assertSee('Transfer ← Spaceship');
});

test('a transfer between two tracked accounts gets no hidden-account label', function () {
    $user = User::factory()->create();
    $a = Account::factory()->for($user)->create();
    $b = Account::factory()->for($user)->create(['name' => 'Everyday Two']);
    $debit = displayFeedRow($user, $a, -5000, 'Own transfer');
    $credit = displayFeedRow($user, $b, 5000, 'Own transfer');
    app(TransferLinker::class)->link($debit, $credit, TransferLinkSource::Manual);

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertDontSee('Transfer →')
        ->assertDontSee('Transfer ←');
});

test('the hidden partner account is eager loaded, not queried per row', function () {
    $user = User::factory()->create();
    $main = Account::factory()->for($user)->create();
    $spaceship = Account::factory()->for($user)->untracked()->create(['name' => 'Spaceship']);
    $linker = app(TransferLinker::class);

    $render = function () use ($user): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($user)->test(TransactionList::class);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $linker->linkToUntrackedAccount(displayFeedRow($user, $main, -1000, 'One'), $spaceship, TransferLinkSource::Manual);
    $withOne = $render();

    foreach ([-2000, -3000, -4000, -5000] as $cents) {
        $linker->linkToUntrackedAccount(displayFeedRow($user, $main, $cents, 'More '.$cents), $spaceship, TransferLinkSource::Manual);
    }

    expect($render())->toBe($withOne);
});
