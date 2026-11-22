<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\BankImport;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CsvImport\BankImportValueMapping;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\FileUploadController;

test('a qif file is read, a category left empty and its transactions imported', function () {
    Storage::fake(FileUploadConfiguration::disk());
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create(['name' => 'Everyday']);
    $food = Category::factory()->for($user)->create(['name' => 'Food']);
    Category::factory()->for($user)->create(['name' => 'Rent']);
    $yesterday = now()->subDay()->format('d/m/Y');
    $path = tempnam(sys_get_temp_dir(), 'import-').'.qif';
    file_put_contents($path, "!Type:Bank\nD{$yesterday}\nT-4.50\nPCoffee\nLFood\n^\nD{$yesterday}\nT-1,200.00\nPLandlord\nLRent\n^\n");

    $this->actingAs($user);

    try {
        $signedPath = app(FileUploadController::class)->validateAndStore(
            [new UploadedFile($path, 'export.qif', 'text/plain', null, true)],
            FileUploadConfiguration::disk(),
        )[0];

        $page = visit('/import-bank')
            ->select('[data-testid="import-bank-preset"]', 'qif')
            ->select('[data-testid="import-bank-account-select"]', (string) $account->id);

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
            ->assertSee('Quicken QIF file')
            ->assertSee('Check your rows')
            ->assertSeeIn('[data-testid="import-bank-clean-counts"]', '2 rows')
            ->assertDontSee('Map your columns')
            ->assertMissing('[data-testid="import-bank-format"]')
            ->assertVisible('[data-testid="import-bank-qif-date-order"]')
            ->assertNoJavaScriptErrors();

        $page->script(sprintf(<<<'JS'
            (() => {
                const select = document.querySelector('[data-testid="map-category-%s"]');
                select.value = '';
                select.dispatchEvent(new Event('change', { bubbles: true }));
            })()
            JS, BankImportValueMapping::key('Rent')));

        $page->click('Confirm and import')
            ->assertSee('Import complete')
            ->assertSee('2 imported')
            ->assertNoJavaScriptErrors();

        $byDescription = Transaction::query()->where('account_id', $account->id)->pluck('category_id', 'description');

        expect(BankImport::query()->sole()->preset->value)->toBe('qif')
            ->and($byDescription['Coffee'])->toBe($food->id)
            ->and($byDescription['Landlord'])->toBeNull()
            ->and(Transaction::query()->where('account_id', $account->id)->pluck('amount')->sort()->values()->all())->toBe([-120000, -450]);
    } finally {
        unlink($path);
    }
});
