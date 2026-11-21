<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\PdfImportStatus;
use App\Enums\TransactionDirection;
use App\Jobs\ExtractStatementPdfJob;
use App\Livewire\ImportBank;
use App\Livewire\PdfImportReview;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\PdfImport;
use App\Models\PdfImportLine;
use App\Models\Statement;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Services\Statement\StatementPdfFactory;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['name' => 'Everyday']);
    $this->statement = Statement::factory()->for($this->user)->create(['period_start' => '2026-03-01', 'period_end' => '2026-03-31']);
    $this->import = PdfImport::factory()->ready()->create(['user_id' => $this->user->id, 'statement_id' => $this->statement->id, 'account_id' => $this->account->id]);
});

function reviewLine(PdfImport $import, string $date, int $cents): PdfImportLine
{
    return PdfImportLine::factory()->for($import)->create(['line_date' => $date, 'raw_date' => $date, 'amount' => $cents, 'direction' => $cents < 0 ? TransactionDirection::Debit : TransactionDirection::Credit, 'description' => 'Line '.$cents]);
}

function reviewFor(User $user, PdfImport $import)
{
    return Livewire::actingAs($user)->test(PdfImportReview::class, ['pdfImport' => $import]);
}

it('publishes from the review screen, audits it and refuses other users\' imports', function () {
    Transaction::factory()->for($this->user)->for($this->account)->create(['post_date' => '2026-03-05', 'amount' => -1000, 'direction' => TransactionDirection::Debit]);
    reviewLine($this->import, '2026-03-05', -1000);
    reviewLine($this->import, '2026-03-12', -3000);
    reviewLine($this->import, '2026-03-13', -500);

    reviewFor($this->user, $this->import)
        ->assertSeeHtml('data-state="matched"')
        ->call('publish')
        ->assertSeeHtml('data-test="count-found">3<')
        ->assertSeeHtml('data-test="count-recorded">1<')
        ->assertSeeHtml('data-test="count-imported">2<');

    $event = AuditEvent::query()->where('action', 'livewire.pdf-import-review.publish')->sole();

    expect($this->import->fresh()->status)->toBe(PdfImportStatus::Published)
        ->and($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);

    Livewire::actingAs(User::factory()->create())->test(PdfImportReview::class, ['pdfImport' => $this->import])->assertStatus(404);
});

it('shows lines with their kind and flags unreadable dates', function () {
    reviewLine($this->import, '2026-03-12', -3000);
    PdfImportLine::factory()->for($this->import)->unreadableDate()->create();

    reviewFor($this->user, $this->import)
        ->assertSeeHtml('data-state="new"')
        ->assertSeeHtml('data-state="waiting"')
        ->assertSee('Date unreadable')
        ->assertSee('31/02/2026');
});

it('reverts a published import and refuses to change its account until then', function () {
    reviewLine($this->import, '2026-03-12', -3000);
    $other = Account::factory()->for($this->user)->create();

    $component = reviewFor($this->user, $this->import)->call('publish');

    $component->set('accountId', (string) $other->id)->call('changeAccount')->assertSee('revert it first');

    expect($this->import->fresh()->account_id)->toBe($this->account->id);

    $component->call('revert');

    expect($this->import->fresh()->status)->toBe(PdfImportStatus::Reverted)
        ->and(Transaction::query()->where('pdf_import_id', $this->import->id)->count())->toBe(0);
});

it('refuses to publish to another user\'s account', function () {
    $foreign = Account::factory()->for(User::factory()->create())->create();
    reviewLine($this->import, '2026-03-12', -3000);

    reviewFor($this->user, $this->import)->set('accountId', (string) $foreign->id)->call('publish')->assertHasErrors('accountId');

    expect($this->import->fresh()->status)->toBe(PdfImportStatus::Ready);
});

it('marks a stuck import failed only after an hour', function () {
    $stuck = PdfImport::factory()->create(['user_id' => $this->user->id, 'statement_id' => $this->statement->id, 'status' => PdfImportStatus::Processing]);

    $component = reviewFor($this->user, $stuck)->assertSee('being read')->call('markFailed')->assertSee('still running');

    $this->travel(61)->minutes();
    $component->call('markFailed');

    expect($stuck->fresh()->status)->toBe(PdfImportStatus::Failed);
});

it('polls without recording an audit event', function () {
    $stuck = PdfImport::factory()->create(['user_id' => $this->user->id, 'statement_id' => $this->statement->id, 'status' => PdfImportStatus::Processing]);

    reviewFor($this->user, $stuck)->call('pollStatus');

    expect(AuditEvent::query()->where('action', 'like', '%pollStatus')->count())->toBe(0);
});

it('serves the review page at its named route to the owner only', function () {
    $this->actingAs($this->user)->get(route('imports.pdf.review', $this->import->id))->assertOk()->assertSeeLivewire(PdfImportReview::class);
    $this->actingAs(User::factory()->create())->get(route('imports.pdf.review', $this->import->id))->assertNotFound();
});

it('sends guests to log in', function () {
    $this->get(route('imports.pdf.review', $this->import->id))->assertRedirect(route('login'));
});

it('starts a pdf import from the import screen and opens its review', function () {
    Storage::fake('local');
    Queue::fake();
    config(['budget.pdf_import.enabled' => true]);

    Livewire::actingAs($this->user)->test(ImportBank::class)
        ->assertSeeHtml('import-bank-pdf')
        ->set('pdfFile', UploadedFile::fake()->createWithContent('march.pdf', StatementPdfFactory::make(['Statement'])))
        ->call('startPdfImport')
        ->assertRedirect(route('imports.pdf.review', PdfImport::query()->latest('id')->value('id')));

    Queue::assertPushed(ExtractStatementPdfJob::class);
    expect(PdfImport::query()->where('user_id', $this->user->id)->count())->toBe(2);
});

it('rejects non-pdf files on the import screen and hides the option while the feature is off', function () {
    config(['budget.pdf_import.enabled' => true]);

    Livewire::actingAs($this->user)->test(ImportBank::class)
        ->set('pdfFile', UploadedFile::fake()->createWithContent('march.csv', "Date,Amount\n"))
        ->call('startPdfImport')
        ->assertHasErrors('pdfFile');

    config(['budget.pdf_import.enabled' => false]);

    Livewire::actingAs($this->user)->test(ImportBank::class)->assertDontSeeHtml('import-bank-pdf');
});

it('shows the refusal message when the starter refuses', function () {
    config(['budget.pdf_import.enabled' => false]);

    Livewire::actingAs($this->user)->test(ImportBank::class)
        ->set('pdfFile', UploadedFile::fake()->createWithContent('march.pdf', StatementPdfFactory::make(['Statement'])))
        ->call('startPdfImport')
        ->assertSee('PDF import is not enabled.');
});

it('refuses a pdf over the size limit on the import screen', function () {
    config(['budget.pdf_import.enabled' => true]);

    Livewire::actingAs($this->user)->test(ImportBank::class)
        ->set('pdfFile', UploadedFile::fake()->create('big.pdf', 25601, 'application/pdf'))
        ->call('startPdfImport')
        ->assertHasErrors('pdfFile');
});

it('caps the pdf size at what extraction accepts', function () {
    config(['services.statement_ai.max_pdf_bytes' => 10 * 1024 * 1024]);

    expect(ImportBank::maxPdfKilobytes())->toBe(10240);
});

it('asks for an account instead of failing when none is chosen', function () {
    reviewLine($this->import, '2026-03-12', -3000);

    reviewFor($this->user, $this->import)->set('accountId', '')->call('publish')->assertHasErrors('accountId')->call('changeAccount')->assertHasErrors('accountId');

    expect($this->import->fresh()->status)->toBe(PdfImportStatus::Ready);
});

it('refuses an untracked account', function () {
    $untracked = Account::factory()->for($this->user)->create(['is_tracked' => false]);
    reviewLine($this->import, '2026-03-12', -3000);

    reviewFor($this->user, $this->import)->set('accountId', (string) $untracked->id)->call('publish')->assertHasErrors('accountId');

    expect($this->import->fresh()->status)->toBe(PdfImportStatus::Ready);
});

it('changes the account of a ready import and records a failure when a refusal happens', function () {
    $other = Account::factory()->for($this->user)->create();
    reviewLine($this->import, '2026-03-12', -3000);

    $component = reviewFor($this->user, $this->import)->set('accountId', (string) $other->id)->call('changeAccount');

    expect($this->import->fresh()->account_id)->toBe($other->id);

    $component->call('publish')->set('accountId', (string) $this->account->id)->call('changeAccount');

    $failure = AuditEvent::query()->where('action', 'livewire.pdf-import-review.changeAccount')->latest('id')->first();

    expect($failure->outcome)->toBe(AuditOutcome::Failure)
        ->and($component->get('accountId'))->toBe((string) $other->id);
});

it('counts waiting lines before an account is chosen', function () {
    $import = PdfImport::factory()->ready()->create(['user_id' => $this->user->id, 'statement_id' => $this->statement->id, 'account_id' => null]);
    PdfImportLine::factory()->for($import)->unreadableDate()->create();

    reviewFor($this->user, $import)->set('accountId', '')->assertSeeHtml('data-test="count-waiting">1<');
});
