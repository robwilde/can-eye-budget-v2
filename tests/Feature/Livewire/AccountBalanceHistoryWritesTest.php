<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountClass;
use App\Enums\AccountGroup;
use App\Livewire\AccountManager;
use App\Livewire\ImportBank;
use App\Models\Account;
use App\Models\User;
use App\Services\CsvImport\CsvColumnMapper;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-05-12');
    $this->user = User::factory()->create();
});

test('records the balance history of a new tracked account', function () {
    Livewire::actingAs($this->user)
        ->test(AccountManager::class)
        ->call('openAddModal')
        ->set('name', 'Everyday')
        ->set('balance', '1500.50')
        ->set('type', AccountClass::Transaction->value)
        ->set('group', AccountGroup::DayToDay->value)
        ->call('save');

    $account = Account::query()->where('user_id', $this->user->id)->sole();
    $today = $account->dailyBalances()->whereDate('balance_date', '2026-05-12')->sole();

    expect($today->end_balance)->toBe(150050);
});

test('records the balance history of a new untracked account', function () {
    Livewire::actingAs($this->user)
        ->test(AccountManager::class)
        ->call('openAddUntrackedModal')
        ->set('name', 'Spaceship')
        ->set('balance', '2000')
        ->set('type', AccountClass::Savings->value)
        ->call('save');

    $account = Account::query()->where('user_id', $this->user->id)->sole();

    expect($account->is_tracked)->toBeFalse()
        ->and($account->dailyBalances()->whereDate('balance_date', '2026-05-12')->sole()->end_balance)->toBe(200000);
});

test('rebuilds history when a csv import sets the current balance', function () {
    Queue::fake();
    $account = Account::factory()->for($this->user)->csvImport()->create(['balance' => 0]);
    app(AccountBalanceHistoryBuilder::class)->rebuild($account);

    $csv = "Date,Description,Amount,Balance\n01/05/2026,Coffee,-5.00,1000.00\n03/05/2026,Pay,2000.00,3000.00\n";

    Livewire::actingAs($this->user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $account->id)
        ->set('file', UploadedFile::fake()->createWithContent('statement.csv', $csv))
        ->call('uploadAndDetectHeaders')
        ->set('mapping.'.CsvColumnMapper::FIELD_BALANCE, 'Balance')
        ->set('currentBalance', '1234.56')
        ->call('confirmImport');

    expect($account->dailyBalances()->whereDate('balance_date', '2026-05-12')->sole()->end_balance)->toBe(123456);
});
