<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountClass;
use App\Models\Account;
use App\Models\RedbarkFeed;
use App\Models\StatementReconciliation;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\FileUploadController;

/**
 * The epic's promise as one flow: sign up, connect Redbark, set up the account, then
 * reconcile last month's statement and close the month.
 */
test('a new user signs up, connects Redbark and reconciles last month', function () {
    $this->travelTo('2026-10-15 10:00:00');
    Storage::fake(FileUploadConfiguration::disk());

    // The sync must run inside the request that dispatches it, so the wizard and the
    // reconciliation see the feed's data without a worker.
    config(['queue.default' => 'sync']);

    // Livewire prunes temp uploads older than 24h by now(); with the clock frozen ahead of
    // the file's real mtime it would delete the statement before the component reads it.
    config(['livewire.temporary_file_upload.cleanup' => false]);

    $feedRow = fn (string $id, string $date, string $description, string $amount): array => [
        'id' => $id,
        'accountId' => 'rb_acc_1',
        'accountName' => 'Everyday Card',
        'status' => 'posted',
        'date' => $date,
        'postDate' => $date,
        'valueDate' => $date,
        'description' => $description,
        'amount' => $amount,
        'direction' => str_starts_with($amount, '-') ? 'debit' : 'credit',
        'category' => 'Shopping',
        'merchantName' => null,
        'merchantCategoryCode' => null,
    ];

    fakeRedbark(
        accounts: [redbarkUpstreamAccount(['name' => 'Everyday Card', 'type' => 'credit-card'])],
        connections: [['id' => 'rb_conn_1', 'category' => 'banking', 'institutionName' => 'Test Bank']],
        transactions: [
            $feedRow('rb_txn_1', '2026-09-05', 'WOOLWORTHS 1234 BONDI', '-42.50'),
            $feedRow('rb_txn_2', '2026-09-12', 'NETFLIX.COM', '-22.99'),
            $feedRow('rb_txn_3', '2026-09-20', 'SALARY ACME PTY', '1500.00'),
            $feedRow('rb_txn_4', '2026-10-03', 'COLES 5678 BONDI', '-18.20'),
        ],
    );

    // Step 1-3: register, connect, set up the account.
    $page = visit('/register')
        ->fill('name', 'Onboarding Tester')
        ->fill('email', 'onboarding@example.com')
        ->fill('password', 'password-12345')
        ->fill('password_confirmation', 'password-12345')
        ->click('[data-test="register-user-button"]')
        ->assertPathIs('/connect-bank')
        ->assertSee('Step 1 of 2')
        ->fill('[data-test="connect-bank-api-key"]', 'rbk_test_0123456789abcdef')
        ->click('[data-test="connect-bank-submit"]')
        ->assertSee('Step 2 of 2')
        ->assertSee('Everyday Card');

    $user = User::query()->where('email', 'onboarding@example.com')->sole();
    $redbarkAccountId = RedbarkFeed::query()->where('user_id', $user->id)->sole()->accounts()->sole()->id;

    $page->select("[data-test=\"redbark-choice-{$redbarkAccountId}\"]", 'new:'.AccountClass::CreditCard->value)
        ->click('[data-test="save-redbark-accounts-button"]')
        ->assertPathIs('/dashboard')
        ->assertMissing('[data-test="dashboard-connect-bank-card"]');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/transactions')
        && str_contains($request->url(), 'from=2026-09-01'));

    $account = Account::query()->where('user_id', $user->id)->sole();

    expect($account->type)->toBe(AccountClass::CreditCard)
        ->and($account->transactions()->count())->toBe(4);

    // Step 5: reconcile September from the dashboard reminder.
    $statement = tempnam(sys_get_temp_dir(), 'onboarding-last-month').'.csv';
    file_put_contents($statement, implode("\n", [
        'Date,Description,Amount',
        now()->subMonthNoOverflow()->setDay(5)->format('d/m/Y').',WOOLWORTHS 1234 BONDI,-42.50',
        now()->subMonthNoOverflow()->setDay(12)->format('d/m/Y').',NETFLIX.COM,-22.99',
        now()->subMonthNoOverflow()->setDay(20)->format('d/m/Y').',SALARY ACME PTY,1500.00',
        now()->subMonthNoOverflow()->setDay(28)->format('d/m/Y').',ACCOUNT FEE,-5.00',
    ])."\n");

    try {
        $page->assertSee("Check last month's statements")
            ->click("[data-test=\"dashboard-statement-due-{$account->id}\"]")
            ->assertPathIs("/accounts/{$account->id}/reconcile");

        // Pest's in-process HTTP server drops multipart bodies (LaravelHttpServer: "@TODO
        // files"), so Livewire's upload POST would arrive empty. Store the file through
        // Livewire's own upload path here, and answer only that one XHR in the page with the
        // signed reference; the rest of the upload (attach, upload bag, _finishUpload) is real.
        $signedPath = app(FileUploadController::class)->validateAndStore(
            [new UploadedFile($statement, 'onboarding-last-month.csv', 'text/csv', null, true)],
            FileUploadConfiguration::disk(),
        )[0];

        $page->script(sprintf(<<<'JS'
            (() => {
                const open = XMLHttpRequest.prototype.open;
                const send = XMLHttpRequest.prototype.send;
                XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                    this.__livewireUpload = String(url).includes('upload-file');
                    return open.call(this, method, url, ...rest);
                };
                XMLHttpRequest.prototype.send = function (body) {
                    if (! this.__livewireUpload) {
                        return send.call(this, body);
                    }
                    Object.defineProperty(this, 'status', { value: 200 });
                    Object.defineProperty(this, 'response', { value: JSON.stringify({ paths: [%s] }) });
                    setTimeout(() => this.dispatchEvent(new Event('load')));
                };
            })()
            JS, json_encode($signedPath)));

        $page->attach('[data-testid="reconcile-file-input"]', $statement);

        // The upload finishes asynchronously; wait until the component holds the file.
        $page->script(<<<'JS'
            new Promise((resolve) => {
                const component = Livewire.all().find((c) => c.name === 'reconcile-statement');
                const timer = setInterval(() => {
                    if (component.$wire.file) {
                        clearInterval(timer);
                        resolve(true);
                    }
                }, 50);
            })
            JS);

        $page->click('[data-testid="reconcile-detect"]')
            ->assertPresent('[data-testid="reconcile-mapping"]')
            ->click('[data-testid="reconcile-submit"]')
            ->assertSeeIn('[data-testid="reconcile-chip-matched"]', '3 matched')
            ->assertSeeIn('[data-testid="reconcile-chip-statement-only"]', '1 statement only')
            ->assertSeeIn('[data-testid="reconcile-chip-feed-only"]', '0 feed only');
    } finally {
        @unlink($statement);
    }

    $reconciliation = StatementReconciliation::query()->where('account_id', $account->id)->sole();
    $stray = $reconciliation->lines()->where('description', 'ACCOUNT FEE')->sole();

    $page->click("[data-testid=\"reconcile-import-{$stray->id}\"]")
        ->assertSeeIn('[data-testid="reconcile-chip-matched"]', '4 matched')
        ->click('[data-testid="reconcile-tick-all-matched"]')
        ->click('[data-testid="reconcile-close"]')
        ->assertVisible('[data-testid="reconcile-closed-banner"]')
        ->assertSee('This month is closed');

    // Step 6: the reminder is gone and the account shows the reconciled pill.
    visit('/dashboard')
        ->assertMissing('[data-test="dashboard-statements-due-card"]')
        ->assertMissing('[data-test="dashboard-connect-bank-card"]');

    visit('/accounts')
        ->assertVisible("[data-test=\"account-reconciled-{$account->id}\"]")
        ->assertSee('Reconciled');

    expect($account->transactions()->count())->toBe(5);
});
