<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Livewire\Dashboard;
use App\Models\Account;
use App\Models\RedbarkAccount;
use App\Models\RedbarkFeed;
use App\Models\StatementReconciliation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-27 10:00:00');
});

function statementsDueAccount(RedbarkFeed $feed, string $name): Account
{
    $account = Account::factory()->create(['user_id' => $feed->user_id, 'name' => $name]);
    RedbarkAccount::factory()->create(['redbark_feed_id' => $feed->id, 'account_id' => $account->id]);

    return $account;
}

function reconcileAugustFor(Account $account, bool $closed): void
{
    $factory = StatementReconciliation::factory();

    ($closed ? $factory->closed() : $factory)->create([
        'user_id' => $account->user_id,
        'account_id' => $account->id,
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
    ]);
}

test('the card lists exactly the redbark accounts without a closed reconciliation for last month', function () {
    $user = User::factory()->withPayCycle()->create();
    $feed = RedbarkFeed::factory()->create(['user_id' => $user->id]);
    $reconciled = statementsDueAccount($feed, 'Everyday');
    $inProgress = statementsDueAccount($feed, 'Savings');
    $untouched = statementsDueAccount($feed, 'Credit Card');
    $manual = Account::factory()->for($user)->create(['name' => 'Cash Tin']);
    reconcileAugustFor($reconciled, closed: true);
    reconcileAugustFor($inProgress, closed: false);

    $component = Livewire::actingAs($user)->test(Dashboard::class);

    expect($component->instance()->statementsDue->pluck('id')->all())->toBe([$untouched->id, $inProgress->id]);

    $component->assertSeeHtml('data-test="dashboard-statements-due-card"')
        ->assertSeeHtml(route('accounts.reconcile', $inProgress))
        ->assertSeeHtml(route('accounts.reconcile', $untouched))
        ->assertDontSeeHtml(route('accounts.reconcile', $reconciled))
        ->assertDontSeeHtml('data-test="dashboard-statement-due-'.$manual->id.'"');
});

test('the card is hidden once every account is reconciled for last month', function () {
    $user = User::factory()->withPayCycle()->create();
    $feed = RedbarkFeed::factory()->create(['user_id' => $user->id]);
    reconcileAugustFor(statementsDueAccount($feed, 'Everyday'), closed: true);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertDontSeeHtml('data-test="dashboard-statements-due-card"');
});

test('the card is hidden without a redbark feed', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertDontSeeHtml('data-test="dashboard-statements-due-card"')
        ->assertSeeHtml('data-test="dashboard-connect-bank-card"');
});

test('the statements-due query count stays flat as accounts increase', function () {
    $user = User::factory()->withPayCycle()->create();
    $feed = RedbarkFeed::factory()->create(['user_id' => $user->id]);

    $queriesFor = function () use ($user): int {
        $component = Livewire::actingAs($user)->test(Dashboard::class);
        $component->instance()->refreshFigures();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->instance()->statementsDue->each(fn (Account $account): string => route('accounts.reconcile', $account));
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    statementsDueAccount($feed, 'One');
    $withOne = $queriesFor();

    foreach (['Two', 'Three', 'Four', 'Five'] as $name) {
        reconcileAugustFor(statementsDueAccount($feed, $name), closed: false);
    }
    $withFive = $queriesFor();

    expect($withOne)->toBe(1)
        ->and($withFive)->toBe($withOne);
});
