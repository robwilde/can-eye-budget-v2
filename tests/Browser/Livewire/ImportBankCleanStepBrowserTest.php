<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\BankImportRow;
use App\Models\Category;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\FileUploadController;

test('an invalid row is fixed in the rows table and the import applies the mapped category and tag', function () {
    Storage::fake(FileUploadConfiguration::disk());
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create(['name' => 'Everyday']);
    $groceries = Category::factory()->for($user)->create(['name' => 'Groceries']);
    $yesterday = now()->subDay()->format('d/m/Y');
    $path = tempnam(sys_get_temp_dir(), 'import-').'.csv';
    file_put_contents($path, "Date,Description,Amount,Category,Tags\n{$yesterday},Coffee,-4.50,Groceries,\n{$yesterday},Hotel,abc,Food,holiday\n");

    $this->actingAs($user);

    try {
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
            ->click('[data-testid="import-bank-review-rows"]')
            ->assertSeeIn('[data-testid="import-bank-clean-counts"]', '2 rows')
            ->assertSeeIn('[data-testid="import-bank-clean-counts"]', '1 invalid')
            ->assertAttribute('[data-testid="import-row-'.BankImportRow::query()->invalid()->sole()->id.'-amount"]', 'aria-invalid', 'true')
            ->assertNoJavaScriptErrors();

        $broken = BankImportRow::query()->invalid()->sole();

        $page->script(sprintf(<<<'JS'
            (() => {
                const input = document.querySelector('[data-testid="import-row-%d-amount"]');
                input.value = '-90.00';
                input.dispatchEvent(new Event('change', { bubbles: true }));
            })()
            JS, $broken->id));

        $page->assertSeeIn('[data-testid="import-bank-clean-counts"]', '0 invalid')
            ->assertNoJavaScriptErrors();

        expect($broken->fresh()->amount)->toBe(-9000);

        $page->script(<<<'JS'
            (() => {
                const select = document.querySelector('[data-testid^="map-tag-"]');
                select.value = 'new';
                select.dispatchEvent(new Event('change', { bubbles: true }));
            })()
            JS);

        $page->click('Confirm and import')
            ->assertSee('Import complete')
            ->assertSee('2 imported')
            ->assertNoJavaScriptErrors();

        $hotel = Transaction::query()->where('account_id', $account->id)->where('description', 'Hotel')->sole();

        expect(Transaction::query()->where('account_id', $account->id)->where('description', 'Coffee')->sole()->category_id)->toBe($groceries->id)
            ->and($hotel->amount)->toBe(-9000)
            ->and($hotel->category_id)->toBeNull()
            ->and($hotel->tags()->pluck('name')->all())->toBe(['holiday'])
            ->and(Tag::query()->where('user_id', $user->id)->count())->toBe(1);
    } finally {
        unlink($path);
    }
});
