<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccount;
use App\Models\GoalSpend;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Connection settings for the scratch MariaDB database, read from the environment. The suite
 * pins the default connection to in-memory SQLite, where lockForUpdate() does nothing, so
 * these tests build their own connection and run only when MARIADB_TEST_DATABASE names a
 * database ending in "_scratch".
 *
 * @return array{host: string, port: string, username: string, password: string, database: string}
 */
function lockOrderSettings(): array
{
    return [
        'host' => getenv('MARIADB_TEST_HOST') ?: 'db',
        'port' => getenv('MARIADB_TEST_PORT') ?: '3306',
        'username' => getenv('MARIADB_TEST_USERNAME') ?: 'root',
        'password' => getenv('MARIADB_TEST_PASSWORD') ?: 'root',
        'database' => (string) getenv('MARIADB_TEST_DATABASE'),
    ];
}

function lockOrderAdmin(): PDO
{
    $settings = lockOrderSettings();

    return new PDO(
        sprintf('mysql:host=%s;port=%s', $settings['host'], $settings['port']),
        $settings['username'],
        $settings['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function lockOrderRebuildDatabase(): void
{
    $database = lockOrderSettings()['database'];

    if (! str_ends_with($database, '_scratch') || $database === getenv('DB_DATABASE')) {
        throw new RuntimeException('MARIADB_TEST_DATABASE must name a throwaway database ending in _scratch.');
    }

    $admin = lockOrderAdmin();
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
    $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

function lockOrderUseScratchConnection(): void
{
    static $migrated = false;

    if (! $migrated) {
        lockOrderRebuildDatabase();
    }

    $settings = lockOrderSettings();

    config([
        'database.connections.lock_order' => [
            'driver' => 'mariadb',
            'host' => $settings['host'],
            'port' => $settings['port'],
            'database' => $settings['database'],
            'username' => $settings['username'],
            'password' => $settings['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ],
        'database.default' => 'lock_order',
    ]);
    DB::purge('lock_order');

    if (! $migrated) {
        Artisan::call('migrate', ['--database' => 'lock_order', '--force' => true]);
        $migrated = true;
    }
}

/**
 * @return array{0: User, 1: Account, 2: Goal}
 */
function lockOrderGoal(int $balance = 10000, int $target = 10000): array
{
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => $balance]);
    $goal = Goal::factory()->for($user)->create(['target_amount' => $target]);
    GoalAccount::factory()->for($goal)->for($account)->create(['earmark_amount' => null]);

    return [$user, $account, $goal];
}

/**
 * Starts recordSpend() in its own PHP process, so it holds its own database connection and
 * blocks on a row lock the way a second request would.
 *
 * @return array{process: resource, pipe: resource, errors: resource}
 */
function lockOrderStartSpend(User $user, Goal $goal, int $amount, ?Account $account = null, ?Transaction $transaction = null): array
{
    $settings = lockOrderSettings();
    $job = json_encode([
        'user' => $user->id,
        'goal' => $goal->id,
        'amount' => $amount,
        'account' => $account?->id,
        'transaction' => $transaction?->id,
    ], JSON_THROW_ON_ERROR);

    $process = proc_open(
        [PHP_BINARY, __DIR__.'/Support/spend-worker.php', $job],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path(),
        [
            ...getenv(),
            'DB_CONNECTION' => 'mariadb',
            'DB_URL' => '',
            'DB_HOST' => $settings['host'],
            'DB_PORT' => $settings['port'],
            'DB_DATABASE' => $settings['database'],
            'DB_USERNAME' => $settings['username'],
            'DB_PASSWORD' => $settings['password'],
        ],
    );

    expect($process)->not->toBeFalse();

    return ['process' => $process, 'pipe' => $pipes[1], 'errors' => $pipes[2]];
}

/**
 * @param  array{process: resource, pipe: resource, errors: resource}  $spend
 */
function lockOrderWaitUntilBlocked(array $spend): void
{
    $admin = lockOrderAdmin();
    $statement = $admin->prepare("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB = ? AND INFO LIKE '%from `accounts`%for update%' AND TIME_MS >= 500");
    $deadline = microtime(true) + 30;

    while (microtime(true) < $deadline) {
        $statement->execute([lockOrderSettings()['database']]);
        $waiting = (int) $statement->fetchColumn();

        if ($waiting > 0) {
            return;
        }

        if (! proc_get_status($spend['process'])['running']) {
            throw new RuntimeException('The spend ended instead of waiting: '.stream_get_contents($spend['pipe']).stream_get_contents($spend['errors']));
        }

        usleep(50000);
    }

    throw new RuntimeException('The spend never blocked on the account lock.');
}

/**
 * @param  array{process: resource, pipe: resource, errors: resource}  $spend
 * @return array{outcome: string, message?: string, class?: string}
 */
function lockOrderFinishSpend(array $spend): array
{
    stream_set_blocking($spend['pipe'], false);
    $output = '';
    $deadline = microtime(true) + 30;

    while (microtime(true) < $deadline) {
        $output .= (string) stream_get_contents($spend['pipe']);

        if (! proc_get_status($spend['process'])['running']) {
            $output .= (string) stream_get_contents($spend['pipe']);

            break;
        }

        usleep(50000);
    }

    if (proc_get_status($spend['process'])['running']) {
        proc_terminate($spend['process']);
        proc_close($spend['process']);

        throw new RuntimeException('The spend did not finish in 30 seconds.');
    }

    proc_close($spend['process']);

    return json_decode(mb_trim($output), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Holds the user's account row locks, starts a spend that has to wait for it, lets $change run while
 * the spend waits, commits, and returns what the spend did.
 *
 * @return array{outcome: string, message?: string, class?: string}
 */
function lockOrderSpendWhileHeld(User $user, Account $account, Goal $goal, int $amount, Closure $change, ?Transaction $transaction = null): array
{
    $connection = DB::connection('lock_order');
    $connection->beginTransaction();

    try {
        $connection->table('accounts')->where('user_id', $user->id)->orderBy('id')->lockForUpdate()->get();

        $spend = lockOrderStartSpend($user, $goal, $amount, $account, $transaction);
        lockOrderWaitUntilBlocked($spend);
        $change();
        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollBack();

        throw $exception;
    }

    return lockOrderFinishSpend($spend);
}

beforeEach(function () {
    if (getenv('MARIADB_TEST_DATABASE') === false || getenv('MARIADB_TEST_DATABASE') === '') {
        $this->markTestSkipped('Needs a scratch MariaDB: MARIADB_TEST_DATABASE=lock_order_scratch ddev exec vendor/bin/pest --testsuite=MariaDb');
    }

    lockOrderUseScratchConnection();
});

afterAll(function () {
    $database = lockOrderSettings()['database'];

    if (str_ends_with($database, '_scratch') && $database !== getenv('DB_DATABASE')) {
        lockOrderAdmin()->exec("DROP DATABASE IF EXISTS `{$database}`");
    }
});

test('a spend with nothing contending for the account is recorded', function () {
    [$user, $account, $goal] = lockOrderGoal();

    $result = lockOrderFinishSpend(lockOrderStartSpend($user, $goal, 8000, $account));

    expect($result['outcome'])->toBe('spent')
        ->and(GoalSpend::query()->where('goal_id', $goal->id)->sum('amount'))->toEqual(8000);
});

test('a spend waiting for the account lock is refused when the balance is reduced meanwhile', function () {
    [$user, $account, $goal] = lockOrderGoal();

    $result = lockOrderSpendWhileHeld($user, $account, $goal, 8000, fn () => $account->update(['balance' => 1000]));

    expect($result['outcome'])->toBe('refused')
        ->and($result['message'])->toContain('more than this goal has set aside')
        ->and(GoalSpend::query()->where('goal_id', $goal->id)->exists())->toBeFalse();
});

test('a spend waiting for the account lock is refused when its transaction is revised meanwhile', function () {
    [$user, $account, $goal] = lockOrderGoal();
    $original = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'direction' => TransactionDirection::Debit,
        'amount' => 8000,
    ]);

    $result = lockOrderSpendWhileHeld($user, $account, $goal, 8000, fn () => Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'direction' => TransactionDirection::Debit,
        'amount' => 8000,
        'parent_transaction_id' => $original->id,
    ]), $original);

    expect($result['outcome'])->toBe('refused')
        ->and($result['message'])->toContain('Choose an outgoing transaction')
        ->and(GoalSpend::query()->where('goal_id', $goal->id)->exists())->toBeFalse();
});

test('an account delete and a spend that overlap both finish without a deadlock', function () {
    [$user, $account, $goal] = lockOrderGoal();

    $result = lockOrderSpendWhileHeld($user, $account, $goal, 8000, fn () => $account->delete());

    expect($result['outcome'])->toBe('refused')
        ->and(Account::query()->whereKey($account->id)->exists())->toBeFalse()
        ->and(GoalAccount::query()->where('goal_id', $goal->id)->exists())->toBeFalse();
});
