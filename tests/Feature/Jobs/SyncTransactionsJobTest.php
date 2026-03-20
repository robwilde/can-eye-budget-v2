<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\BasiqServiceContract;
use App\DTOs\BasiqAccount;
use App\DTOs\BasiqJob;
use App\DTOs\BasiqTransaction;
use App\Jobs\SyncTransactionsJob;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\LazyCollection;
use Mockery\MockInterface;

function fakeBasiqJobService(string $status = 'success', ?callable $configure = null): MockInterface
{
    $steps = match ($status) {
        'pending' => [['title' => 'verify-credentials', 'status' => 'in_progress']],
        'failed' => [['title' => 'verify-credentials', 'status' => 'failed']],
        default => [['title' => 'verify-credentials', 'status' => 'success']],
    };

    $mock = Mockery::mock(BasiqServiceContract::class);

    $mock
        ->shouldReceive('getJob')
        ->andReturn(BasiqJob::from(['id' => 'job-1', 'steps' => $steps]))
        ->byDefault();

    $mock
        ->shouldReceive('getAccounts')
        ->andReturn(new Collection())
        ->byDefault();

    $mock
        ->shouldReceive('paginateTransactions')
        ->andReturn(LazyCollection::empty())
        ->byDefault();

    if ($configure) {
        $configure($mock);
    }

    app()->instance(BasiqServiceContract::class, $mock);

    return $mock;
}

/** @param  array<string, mixed>  $overrides */
function makeBasiqAccount(string $id = 'basiq-acc-1', array $overrides = []): BasiqAccount
{
    return BasiqAccount::from(array_merge([
        'id' => $id,
        'name' => 'Everyday Account',
        'institution' => 'Commonwealth Bank',
        'class' => ['type' => 'transaction'],
        'balance' => '1234.56',
        'currency' => 'AUD',
        'status' => 'active',
    ], $overrides));
}

function makeBasiqTransaction(string $id = 'txn-1', string $account = 'basiq-acc-1', array $overrides = []): BasiqTransaction
{
    return BasiqTransaction::from(array_merge([
        'id' => $id,
        'amount' => '-42.50',
        'direction' => 'debit',
        'description' => 'WOOLWORTHS 1234',
        'postDate' => '2026-03-10',
        'transactionDate' => '2026-03-09',
        'account' => $account,
        'status' => 'posted',
    ], $overrides));
}

test('null jobId skips polling and syncs directly', function () {
    $user = User::factory()->withBasiq()->create();

    $mock = fakeBasiqJobService('success', function (MockInterface $mock) {
        $mock
            ->shouldReceive('getAccounts')
            ->once()
            ->andReturn(new Collection([makeBasiqAccount()]));
    });

    $mock->shouldNotReceive('getJob');

    new SyncTransactionsJob($user)->handle(app(BasiqServiceContract::class));

    expect(Account::count())->toBe(1);
});

test('pending job releases back to queue', function () {
    Queue::fake();
    $user = User::factory()->withBasiq()->create();

    fakeBasiqJobService('pending');

    $job = new SyncTransactionsJob($user, 'job-1');
    $job->handle(app(BasiqServiceContract::class));

    expect($job->job)->toBeNull();
});

test('failed job logs warning and stops', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with('Basiq job failed', Mockery::on(fn ($ctx) => $ctx['jobId'] === 'job-1'));

    $user = User::factory()->withBasiq()->create();

    fakeBasiqJobService('failed');

    new SyncTransactionsJob($user, 'job-1')->handle(app(BasiqServiceContract::class));

    expect(Account::count())
        ->toBe(0)
        ->and(Transaction::count())->toBe(0);
});

test('successful job syncs accounts via updateOrCreate', function () {
    $user = User::factory()->withBasiq()->create();

    fakeBasiqJobService('success', function (MockInterface $mock) {
        $mock
            ->shouldReceive('getAccounts')
            ->once()
            ->andReturn(new Collection([
                makeBasiqAccount('basiq-acc-1', ['name' => 'Everyday', 'balance' => '500.00']),
                makeBasiqAccount('basiq-acc-2', ['name' => 'Savings', 'class' => ['type' => 'savings'], 'balance' => '10000.00']),
            ]));
    });

    new SyncTransactionsJob($user, 'job-1')->handle(app(BasiqServiceContract::class));

    expect(Account::count())->toBe(2);

    $account = Account::where('basiq_account_id', 'basiq-acc-1')->first();
    expect($account)
        ->user_id->toBe($user->id)
        ->name->toBe('Everyday')
        ->balance->toBe(50000)
        ->currency->toBe('AUD');
});

test('successful job syncs transactions with correct field mapping', function () {
    $user = User::factory()->withBasiq()->create();
    $enrich = [
        'merchant' => ['businessName' => 'Woolworths'],
        'category' => ['anzsic' => ['code' => '4111']],
    ];

    fakeBasiqJobService('success', function (MockInterface $mock) use ($enrich) {
        $mock
            ->shouldReceive('getAccounts')
            ->andReturn(new Collection([makeBasiqAccount()]));

        $mock
            ->shouldReceive('paginateTransactions')
            ->andReturn(LazyCollection::make([
                makeBasiqTransaction('txn-1', 'basiq-acc-1', [
                    'amount' => '-42.50',
                    'direction' => 'debit',
                    'description' => 'WOOLWORTHS 1234',
                    'postDate' => '2026-03-10',
                    'transactionDate' => '2026-03-09',
                    'status' => 'posted',
                    'enrich' => $enrich,
                ]),
            ]));
    });

    new SyncTransactionsJob($user, 'job-1')->handle(app(BasiqServiceContract::class));

    $txn = Transaction::where('basiq_id', 'txn-1')->first();
    expect($txn)
        ->user_id->toBe($user->id)
        ->amount->toBe(-4250)
        ->direction->value->toBe('debit')
        ->description->toBe('WOOLWORTHS 1234')
        ->post_date->format('Y-m-d')->toBe('2026-03-10')
        ->transaction_date->format('Y-m-d')->toBe('2026-03-09')
        ->status->value->toBe('posted')
        ->basiq_account_id->toBe('basiq-acc-1')
        ->merchant_name->toBe('Woolworths')
        ->anzsic_code->toBe('4111')
        ->enrich_data->toBe($enrich);
});

test('amount conversion handles positive, negative, and zero values', function (string $input, int $expected) {
    $user = User::factory()->withBasiq()->create();

    fakeBasiqJobService('success', function (MockInterface $mock) use ($input) {
        $mock
            ->shouldReceive('getAccounts')
            ->andReturn(new Collection([makeBasiqAccount()]));

        $mock
            ->shouldReceive('paginateTransactions')
            ->andReturn(LazyCollection::make([
                makeBasiqTransaction('txn-1', 'basiq-acc-1', [
                    'amount' => $input,
                    'direction' => 'debit',
                ]),
            ]));
    });

    new SyncTransactionsJob($user, 'job-1')->handle(app(BasiqServiceContract::class));

    expect(Transaction::first()->amount)->toBe($expected);
})->with([
    'positive' => ['100.00', 10000],
    'negative' => ['-42.50', -4250],
    'zero' => ['0.00', 0],
    'small cents' => ['0.01', 1],
]);

test('incremental sync uses postDate filter when last_synced_at is set', function () {
    $user = User::factory()->withBasiq()->create([
        'last_synced_at' => '2026-03-15 00:00:00',
    ]);

    fakeBasiqJobService('success', function (MockInterface $mock) use ($user) {
        $mock
            ->shouldReceive('getAccounts')
            ->andReturn(new Collection([makeBasiqAccount()]));

        $mock
            ->shouldReceive('paginateTransactions')
            ->once()
            ->with($user->basiq_user_id, ["transaction.postDate.gt('2026-03-15')"])
            ->andReturn(LazyCollection::empty());
    });

    new SyncTransactionsJob($user, 'job-1')->handle(app(BasiqServiceContract::class));
});

test('full sync uses no filter when last_synced_at is null', function () {
    $user = User::factory()->withBasiq()->create([
        'last_synced_at' => null,
    ]);

    fakeBasiqJobService('success', function (MockInterface $mock) {
        $mock
            ->shouldReceive('getAccounts')
            ->andReturn(new Collection([makeBasiqAccount()]));

        $mock
            ->shouldReceive('paginateTransactions')
            ->once()
            ->with(Mockery::any(), null)
            ->andReturn(LazyCollection::empty());
    });

    new SyncTransactionsJob($user, 'job-1')->handle(app(BasiqServiceContract::class));
});

test('updates last_synced_at after successful sync', function () {
    $user = User::factory()->withBasiq()->create([
        'last_synced_at' => null,
    ]);

    fakeBasiqJobService('success', function (MockInterface $mock) {
        $mock
            ->shouldReceive('getAccounts')
            ->andReturn(new Collection([makeBasiqAccount()]));
    });

    new SyncTransactionsJob($user, 'job-1')->handle(app(BasiqServiceContract::class));

    $user->refresh();
    expect($user->last_synced_at)->not
        ->toBeNull()
        ->and($user->last_synced_at->isToday())->toBeTrue();
});

test('transactions for unknown accounts are skipped', function () {
    $user = User::factory()->withBasiq()->create();

    fakeBasiqJobService('success', function (MockInterface $mock) {
        $mock
            ->shouldReceive('getAccounts')
            ->andReturn(new Collection([makeBasiqAccount('basiq-acc-1')]));

        $mock
            ->shouldReceive('paginateTransactions')
            ->andReturn(LazyCollection::make([
                makeBasiqTransaction('txn-known', 'basiq-acc-1'),
                makeBasiqTransaction('txn-unknown', 'basiq-acc-999'),
            ]));
    });

    new SyncTransactionsJob($user, 'job-1')->handle(app(BasiqServiceContract::class));

    expect(Transaction::count())
        ->toBe(1)
        ->and(Transaction::first()->basiq_id)->toBe('txn-known');
});

test('transactions with null postDate are skipped', function () {
    $user = User::factory()->withBasiq()->create();

    fakeBasiqJobService('success', function (MockInterface $mock) {
        $mock
            ->shouldReceive('getAccounts')
            ->andReturn(new Collection([makeBasiqAccount()]));

        $mock
            ->shouldReceive('paginateTransactions')
            ->andReturn(LazyCollection::make([
                makeBasiqTransaction('txn-posted', 'basiq-acc-1', ['postDate' => '2026-03-10']),
                makeBasiqTransaction('txn-pending', 'basiq-acc-1', ['postDate' => null]),
            ]));
    });

    new SyncTransactionsJob($user, 'job-1')->handle(app(BasiqServiceContract::class));

    expect(Transaction::count())
        ->toBe(1)
        ->and(Transaction::first()->basiq_id)->toBe('txn-posted');
});

test('updateOrCreate prevents duplicate records on re-run', function () {
    $user = User::factory()->withBasiq()->create();

    $configureMock = function (MockInterface $mock) {
        $mock
            ->shouldReceive('getAccounts')
            ->andReturn(new Collection([makeBasiqAccount()]));

        $mock
            ->shouldReceive('paginateTransactions')
            ->andReturn(LazyCollection::make([
                makeBasiqTransaction('txn-1', 'basiq-acc-1'),
            ]));
    };

    fakeBasiqJobService('success', $configureMock);
    new SyncTransactionsJob($user, 'job-1')->handle(app(BasiqServiceContract::class));

    fakeBasiqJobService('success', $configureMock);
    new SyncTransactionsJob($user, 'job-2')->handle(app(BasiqServiceContract::class));

    expect(Account::count())
        ->toBe(1)
        ->and(Transaction::count())->toBe(1);
});

test('processes transactions across multiple DTOs', function () {
    $user = User::factory()->withBasiq()->create();

    fakeBasiqJobService('success', function (MockInterface $mock) {
        $mock
            ->shouldReceive('getAccounts')
            ->andReturn(new Collection([makeBasiqAccount()]));

        $mock
            ->shouldReceive('paginateTransactions')
            ->andReturn(LazyCollection::make([
                makeBasiqTransaction('txn-1', 'basiq-acc-1', ['amount' => '-10.00']),
                makeBasiqTransaction('txn-2', 'basiq-acc-1', ['amount' => '-20.00']),
                makeBasiqTransaction('txn-3', 'basiq-acc-1', ['amount' => '50.00', 'direction' => 'credit']),
            ]));
    });

    new SyncTransactionsJob($user, 'job-1')->handle(app(BasiqServiceContract::class));

    expect(Transaction::count())->toBe(3);
});
