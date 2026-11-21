<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BankImportStatus;
use App\Models\Account;
use App\Models\BankImport;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\FileUploadController;

test('a semicolon csv with european numbers is imported, reverted and shown again in the preview', function () {
    Storage::fake(FileUploadConfiguration::disk());
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create(['name' => 'Everyday']);
    $yesterday = now()->subDay()->format('d/m/Y');
    $path = tempnam(sys_get_temp_dir(), 'import-').'.csv';
    file_put_contents($path, "Date;Description;Amount\n{$yesterday};Coffee;-1.234,56\n{$yesterday};Salary;2.000,00\n");

    $this->actingAs($user);

    try {
        // Pest's in-process HTTP server drops multipart bodies, so the file is stored through
        // Livewire's own upload path here and only the upload XHR is answered in the page with
        // the signed reference; attach, the upload bag and _finishUpload stay real.
        $signedPath = app(FileUploadController::class)->validateAndStore(
            [new UploadedFile($path, 'statement.csv', 'text/csv', null, true)],
            FileUploadConfiguration::disk(),
        )[0];

        $page = visit('/import-bank')->select('[data-testid="import-bank-account-select"]', (string) $account->id);

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

        $page->attach('[data-testid="import-bank-file-input"]', $path);

        $page->script(<<<'JS'
            new Promise((resolve) => {
                const component = Livewire.all().find((c) => c.name === 'import-bank');
                const timer = setInterval(() => {
                    if (component.$wire.file) {
                        clearInterval(timer);
                        resolve(true);
                    }
                }, 50);
            })
            JS);

        $page->click('Detect columns')
            ->assertSee('Map your columns')
            ->assertSee('How is this file written?')
            ->select('[data-testid="import-bank-number-format"]', 'dot_comma')
            ->assertSeeIn('[data-testid="import-bank-summary-rows"]', '2 rows')
            ->assertDontSee('invalid')
            ->assertNoJavaScriptErrors()
            ->click('Confirm and import')
            ->assertSee('Import complete')
            ->assertSee('2 imported');

        $imported = Transaction::query()->where('account_id', $account->id)->orderBy('id')->get();

        expect($imported)->toHaveCount(2)
            ->and($imported->pluck('amount')->all())->toBe([-123456, 200000]);

        $page->script('window.confirm = () => true');

        $page->click('Revert import')
            ->assertSee('Map your columns')
            ->assertNoJavaScriptErrors();

        expect(Transaction::query()->where('account_id', $account->id)->count())->toBe(0)
            ->and(Transaction::onlyTrashed()->where('account_id', $account->id)->count())->toBe(2)
            ->and(BankImport::query()->where('user_id', $user->id)->sole()->status)->toBe(BankImportStatus::Previewing);
    } finally {
        unlink($path);
    }
});
