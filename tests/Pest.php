<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\RawEmail;
use App\Enums\ImportSource;
use App\Models\Account;
use App\Models\RedbarkAccount;
use App\Models\RedbarkFeed;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Run the real `docker/entrypoint.sh` in a throwaway directory with the named
 * binaries stubbed onto PATH, and report what happened. The script is executed
 * rather than reproduced so a test cannot drift from the file it pins.
 *
 * Every run gets its own directory, so the `storage/` and `bootstrap/cache`
 * trees the script creates cannot collide under `--parallel`.
 *
 * @param  array<string, string>  $stubs  binary name => sh body written after `#!/bin/sh`
 * @param  array<string, string>  $env  the whole environment; PATH is prepended automatically
 * @param  list<string>  $arguments  argv handed to the script
 * @param  array<string, string>  $files  relative path => contents, written into the working directory first
 * @return array{status: int, stdout: list<string>, stderr: string}
 */
function runEntrypoint(array $stubs, array $env = [], array $arguments = [], array $files = []): array
{
    $script = dirname(__DIR__).'/docker/entrypoint.sh';

    $base = sys_get_temp_dir().'/entrypoint-'.getmypid().'-'.bin2hex(random_bytes(8));
    $work = $base.'/work';

    mkdir($base.'/bin', 0o755, true);
    mkdir($work, 0o755, true);

    foreach ($stubs as $name => $body) {
        file_put_contents($base.'/bin/'.$name, "#!/bin/sh\n".$body."\n");
        chmod($base.'/bin/'.$name, 0o755);
    }

    foreach ($files as $path => $contents) {
        file_put_contents($work.'/'.$path, $contents);
    }

    $assignments = 'PATH='.escapeshellarg($base.'/bin:/usr/bin:/bin');

    foreach ($env as $name => $value) {
        $assignments .= ' '.$name.'='.escapeshellarg($value);
    }

    $command = 'cd '.escapeshellarg($work)
        .' && env -i '.$assignments
        .' sh '.escapeshellarg($script);

    foreach ($arguments as $argument) {
        $command .= ' '.escapeshellarg($argument);
    }

    $command .= ' 2>'.escapeshellarg($base.'/stderr');

    $stdout = [];
    $status = 0;
    exec($command, $stdout, $status);

    $stderr = (string) file_get_contents($base.'/stderr');

    deleteEntrypointFixture($base);

    return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
}

function deleteEntrypointFixture(string $path): void
{
    if (! is_dir($path)) {
        @unlink($path);

        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            deleteEntrypointFixture($path.'/'.$entry);
        }
    }

    @rmdir($path);
}

/*
| Redbark API fakes, shared by the Feature and Browser suites.
*/

/**
 * @param  list<array<string, mixed>>  $accounts
 * @param  list<array<string, mixed>>  $connections
 * @param  list<array<string, mixed>>  $transactions
 * @param  list<array<string, mixed>>  $balances
 */
function fakeRedbark(
    array $accounts = [],
    array $connections = [],
    array $transactions = [],
    array $balances = [],
): void {
    // Http::fake() appends stubs and resolves on first match, so a second call would be
    // unreachable. Swap in a fresh factory so each call is the authoritative one and a
    // test can re-fake between two sync runs.
    Http::swap(new Factory);

    Http::fake([
        '*/connections*' => Http::response(['data' => $connections, 'pagination' => ['hasMore' => false]]),
        '*/accounts*' => Http::response(['data' => $accounts, 'pagination' => ['hasMore' => false]]),
        '*/transactions*' => Http::response(['data' => $transactions, 'pagination' => ['hasMore' => false]]),
        '*/balances*' => Http::response(['data' => $balances, 'pagination' => ['hasMore' => false]]),
    ]);
}

/** @param  array<string, mixed>  $overrides */
function redbarkUpstreamAccount(array $overrides = []): array
{
    return [
        'id' => 'rb_acc_1',
        'connectionId' => 'rb_conn_1',
        'name' => 'Everyday Account',
        'type' => 'transaction',
        'institutionName' => 'Test Bank',
        'accountNumber' => '****4321',
        'currency' => 'AUD',
        ...$overrides,
    ];
}

/** @return array{0: RedbarkFeed, 1: RedbarkAccount, 2: Account} */
function linkedRedbarkFeed(array $feedOverrides = [], array $redbarkAccountOverrides = []): array
{
    $feed = RedbarkFeed::factory()->create($feedOverrides);
    $account = Account::factory()->for($feed->user)->create(['import_source' => ImportSource::Redbark]);

    $redbarkAccount = RedbarkAccount::factory()->create([
        'redbark_feed_id' => $feed->id,
        'account_id' => $account->id,
        'redbark_account_id' => 'rb_acc_1',
        'bank_connection_id' => 'rb_conn_1',
        'current_balance' => null,
        ...$redbarkAccountOverrides,
    ]);

    return [$feed, $redbarkAccount, $account];
}

/*
| BNPL schedule emails, shared by the receipt strategy, importer, scan and linker tests.
*/

/**
 * A PayPal Pay in 4 receipt built from tests/Fixtures/emails/paypal-payin4-receipt-<fixture>.html.
 * The fixtures are synthetic: loan eacfa072-…, seller "Umart Online", four
 * fortnightly instalments from 21 July 2026 ('first') or the final one on
 * 1 September 2026 ('last').
 *
 * @param  array<string, string>  $replace  search => replacement applied to the HTML
 */
function payPalReceiptEmail(
    string $fixture = 'first',
    string $messageId = 'paypal-receipt-first@mail.test',
    array $replace = [],
    ?CarbonImmutable $date = null,
): RawEmail {
    $html = (string) file_get_contents(base_path("tests/Fixtures/emails/paypal-payin4-receipt-$fixture.html"));

    return new RawEmail(
        messageId: $messageId,
        subject: 'Your PayPal Pay in 4 payment went through',
        fromName: 'PayPal',
        fromAddress: 'service@paypal.com.au',
        date: $date ?? CarbonImmutable::parse($fixture === 'last' ? '2026-08-31 18:00' : '2026-07-20 18:00'),
        textBody: null,
        htmlBody: strtr($html, $replace),
    );
}

/**
 * The receipt for instalment $number (1–4) of the fixture loan: $50.25 on
 * 21 July, 4 August and 18 August 2026, $50.24 on 1 September 2026. Sent the
 * evening before its Posted-on date, as PayPal does. Instalments 2 and 3 are
 * derived from the first fixture (Posted on, balance and remaining schedule).
 */
function payPalInstalmentReceiptEmail(int $number, ?string $messageId = null): RawEmail
{
    $messageId ??= "paypal-receipt-$number@mail.test";
    $schedule = '$50.25&nbsp;AUD on 4 August 2026 $50.25&nbsp;AUD on 18 August 2026 $50.24&nbsp;AUD on 1 September 2026';

    [$postedOn, $balance, $remaining] = match ($number) {
        1 => ['21 July 2026', '$150.74', $schedule],
        2 => ['4 August 2026', '$100.49', '$50.25&nbsp;AUD on 18 August 2026 $50.24&nbsp;AUD on 1 September 2026'],
        3 => ['18 August 2026', '$50.24', '$50.24&nbsp;AUD on 1 September 2026'],
        4 => ['1 September 2026', '$0.00', ''],
    };

    $date = CarbonImmutable::createFromFormat('!j F Y', $postedOn)->subDay()->setTime(18, 0);

    if ($number === 4) {
        return payPalReceiptEmail('last', $messageId, date: $date);
    }

    return payPalReceiptEmail('first', $messageId, [
        '21 July 2026' => $postedOn,
        '$150.74' => $balance,
        $schedule => $remaining,
    ], $date);
}

function auditSentryEvent(): Sentry\Event
{
    $event = Sentry\Event::createEvent();

    Sentry\SentrySdk::getCurrentHub()->configureScope(function (Sentry\State\Scope $scope) use (&$event): void {
        $event = $scope->applyToEvent($event) ?? $event;
    });

    return $event;
}

/** @return list<Monolog\LogRecord> */
function auditLogRecords(): array
{
    $handler = Illuminate\Support\Facades\Log::channel('audit')->getLogger()->getHandlers()[0];

    return $handler->getRecords();
}
