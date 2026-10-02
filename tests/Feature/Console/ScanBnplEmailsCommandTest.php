<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\ScheduleSource;
use App\DTOs\RawEmail;
use App\Models\Account;
use App\Models\BnplOrder;
use App\Models\PlannedTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Bind a mailbox that returns $emails for every query and records the queries.
 *
 * @param  list<RawEmail>  $emails
 */
function fakeScheduleSource(array $emails = [], ?Throwable $failure = null): object
{
    $source = new class($emails, $failure) implements ScheduleSource
    {
        /** @var list<string> */
        public array $queries = [];

        /** @param list<RawEmail> $emails */
        public function __construct(private readonly array $emails, private readonly ?Throwable $failure) {}

        public function fetch(string $query, int $limit): array
        {
            $this->queries[] = $query;

            if ($this->failure !== null) {
                throw $this->failure;
            }

            return $this->emails;
        }
    };

    app()->instance(ScheduleSource::class, $source);

    return $source;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-25 04:00'));
    config([
        'budget.bnpl_email_import' => true,
        'imap.accounts.gmail.username' => 'budget@example.test',
        'imap.accounts.gmail.password' => 'app-password',
    ]);
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->user->update(['primary_account_id' => $this->account->id]);
});

test('the scan does nothing while the import flag is off', function () {
    config(['budget.bnpl_email_import' => false]);
    $source = fakeScheduleSource([payPalReceiptEmail()]);

    $this->artisan('app:scan-bnpl-emails')
        ->expectsOutput('BNPL email import is disabled (BUDGET_BNPL_EMAIL_IMPORT).')
        ->assertSuccessful();

    expect($source->queries)->toBe([])
        ->and(BnplOrder::query()->count())->toBe(0);
});

test('the scan does nothing while Gmail is not configured', function () {
    config(['imap.accounts.gmail.password' => '']);
    $source = fakeScheduleSource([payPalReceiptEmail()]);

    $this->artisan('app:scan-bnpl-emails')
        ->expectsOutput('Gmail is not configured (GMAIL_USERNAME / GMAIL_APP_PASSWORD).')
        ->assertSuccessful();

    expect($source->queries)->toBe([])
        ->and(BnplOrder::query()->count())->toBe(0);
});

test('with more than one user the owner must be named', function () {
    $other = User::factory()->create();
    Account::factory()->for($other)->create();
    fakeScheduleSource([payPalReceiptEmail()]);

    $this->artisan('app:scan-bnpl-emails')
        ->expectsOutput('More than one user exists; pass --user=<id>.')
        ->assertFailed();

    expect(BnplOrder::query()->count())->toBe(0);

    $this->artisan('app:scan-bnpl-emails', ['--user' => (string) $this->user->id])->assertSuccessful();

    expect(BnplOrder::query()->sole()->user_id)->toBe($this->user->id);
});

test('an unknown --user fails without writing', function () {
    fakeScheduleSource([payPalReceiptEmail()]);

    $this->artisan('app:scan-bnpl-emails', ['--user' => '999'])
        ->expectsOutput('User 999 does not exist.')
        ->assertFailed();

    expect(BnplOrder::query()->count())->toBe(0);
});

test('a malformed --user is rejected instead of being read as a user id', function (string $user) {
    $source = fakeScheduleSource([payPalReceiptEmail()]);

    $this->artisan('app:scan-bnpl-emails', ['--user' => $user])
        ->expectsOutput('--user must be a user id (a positive whole number).')
        ->assertFailed();

    expect($source->queries)->toBe([])
        ->and(BnplOrder::query()->count())->toBe(0);
})->with([
    'trailing text' => [fn (): string => $this->user->id.'oops'],
    'zero' => ['0'],
    'negative' => [fn (): string => '-'.$this->user->id],
    'decimal' => [fn (): string => $this->user->id.'.0'],
    'empty' => [''],
]);

test('the scan imports receipts into orders and plans and reports the counts', function () {
    $source = fakeScheduleSource([
        payPalReceiptEmail(),
        new RawEmail('not-a-receipt@mail.test', 'Your PayPal Pay in 4 payment went through', 'PayPal', 'service@paypal.com.au', null, 'Nothing to see here.', null),
    ]);

    $this->artisan('app:scan-bnpl-emails')
        ->expectsOutput('Scanned 2 email(s): 1 order(s) created, 1 plan(s) created, 1 skipped, 0 failed.')
        ->assertSuccessful();

    expect($source->queries)->toBe(['from:paypal.com.au subject:(Pay in 4 payment went through) after:2026/07/14'])
        ->and(BnplOrder::query()->count())->toBe(1)
        ->and(PlannedTransaction::query()->sole()->description)->toBe('PayPal Pay in 4 - Umart Online');
});

test('a re-run over the same window creates nothing new', function () {
    fakeScheduleSource([payPalReceiptEmail(), payPalInstalmentReceiptEmail(2)]);

    $this->artisan('app:scan-bnpl-emails')->assertSuccessful();

    $this->artisan('app:scan-bnpl-emails')
        ->expectsOutput('Scanned 2 email(s): 0 order(s) created, 0 plan(s) created, 0 skipped, 0 failed.')
        ->assertSuccessful();

    expect(BnplOrder::query()->count())->toBe(1)
        ->and(PlannedTransaction::query()->count())->toBe(1);
});

test('the earliest receipt of a loan seeds its order whatever the mailbox order', function () {
    fakeScheduleSource([payPalInstalmentReceiptEmail(2), payPalReceiptEmail()]);

    $this->artisan('app:scan-bnpl-emails')->assertSuccessful();

    $order = BnplOrder::query()->sole();

    expect($order->gmail_message_id)->toBe('paypal-receipt-first@mail.test')
        ->and($order->instalment_count)->toBe(4)
        ->and($order->first_due_date->toDateString())->toBe('2026-07-21');
});

test('a dry run parses the receipts and writes nothing', function () {
    fakeScheduleSource([payPalReceiptEmail(), payPalInstalmentReceiptEmail(2)]);

    $this->artisan('app:scan-bnpl-emails', ['--dry-run' => true])
        ->expectsOutput('Dry run: scanned 2 email(s): 2 schedule(s) parsed, 0 skipped; nothing written.')
        ->assertSuccessful();

    expect(BnplOrder::query()->count())->toBe(0)
        ->and(PlannedTransaction::query()->count())->toBe(0);
});

test('--since accepts a date or an interval and rejects anything else', function (string $since, string $after) {
    $source = fakeScheduleSource();

    $this->artisan('app:scan-bnpl-emails', ['--since' => $since])->assertSuccessful();

    expect($source->queries[0])->toEndWith('after:'.$after);
})->with([
    'date' => ['2026-07-01', '2026/06/30'],
    'days' => ['60d', '2026/05/25'],
    'weeks' => ['2w', '2026/07/10'],
    'months' => ['12m', '2025/07/24'],
]);

test('an invalid --since fails before reading the mailbox', function (string $since) {
    $source = fakeScheduleSource([payPalReceiptEmail()]);

    $this->artisan('app:scan-bnpl-emails', ['--since' => $since])
        ->expectsOutput('--since must be Y-m-d or <n>d|w|m.')
        ->assertFailed();

    expect($source->queries)->toBe([]);
})->with(['yesterday', '2026-02-30', '10y']);

test('a mailbox failure fails the run', function () {
    fakeScheduleSource(failure: new RuntimeException('IMAP login refused'));

    $this->artisan('app:scan-bnpl-emails')
        ->expectsOutput('Gmail fetch failed: IMAP login refused')
        ->assertFailed();
});

test('an email that cannot be imported is reported and the run continues', function () {
    $this->user->update(['primary_account_id' => null]);
    $this->account->delete();
    fakeScheduleSource([payPalReceiptEmail()]);

    $this->artisan('app:scan-bnpl-emails')
        ->expectsOutput('Scanned 1 email(s): 0 order(s) created, 0 plan(s) created, 0 skipped, 1 failed.')
        ->assertSuccessful();

    expect(BnplOrder::query()->count())->toBe(0);
});

test('the scan is scheduled nightly at 04:00 without overlapping', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('app:scan-bnpl-emails')
        ->assertSuccessful();

    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'app:scan-bnpl-emails'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 4 * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue()
        ->and($events->first()->description)->toBe('bnpl:scan-emails');
});
