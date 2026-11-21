<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\StatementReviewStatus;
use App\Livewire\StatementVault;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Statement;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
});

function vaultFor(User $user)
{
    return Livewire::actingAs($user)->test(StatementVault::class);
}

function vaultCsv(string $name, string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

it('links a statement to its suggested account and records an audit event', function () {
    $account = Account::factory()->for($this->user)->create();
    $statement = Statement::factory()->for($this->user)->create(['suggested_account_id' => $account->id, 'suggestion_confidence' => '0.7500']);

    vaultFor($this->user)->call('acceptSuggestion', $statement->id);

    $event = AuditEvent::query()->where('action', 'livewire.statement-vault.acceptSuggestion')->sole();

    expect($statement->fresh()->account_id)->toBe($account->id)
        ->and($statement->fresh()->review_status)->toBe(StatementReviewStatus::Linked)
        ->and($statement->fresh()->suggested_account_id)->toBeNull()
        ->and($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});

it('unlinks a statement back to the inbox', function () {
    $account = Account::factory()->for($this->user)->create();
    $statement = Statement::factory()->for($this->user)->create(['account_id' => $account->id, 'original_filename' => 'march.pdf']);

    $component = vaultFor($this->user)->assertSeeInOrder(['Linked statements', 'march.pdf'])->call('unlink', $statement->id);

    expect($statement->fresh()->account_id)->toBeNull()
        ->and($statement->fresh()->review_status)->toBe(StatementReviewStatus::Unmatched)
        ->and($component->instance()->inbox->pluck('id')->all())->toBe([$statement->id]);
});

it('links a statement to a chosen own account', function () {
    $account = Account::factory()->for($this->user)->create();
    $statement = Statement::factory()->for($this->user)->create();

    vaultFor($this->user)->call('linkTo', $statement->id, $account->id);

    expect($statement->fresh()->account_id)->toBe($account->id)
        ->and($statement->fresh()->review_status)->toBe(StatementReviewStatus::Linked);
});

it('refuses to show, change or download another user\'s statement', function () {
    Storage::disk('local')->put('statements/theirs.pdf', '%PDF-1.4');
    $other = User::factory()->create();
    $theirAccount = Account::factory()->for($other)->create();
    $mine = Account::factory()->for($this->user)->create();
    $theirs = Statement::factory()->for($other)->create([
        'stored_path' => 'statements/theirs.pdf',
        'original_filename' => 'secret-statement.pdf',
        'suggested_account_id' => $theirAccount->id,
    ]);

    vaultFor($this->user)
        ->assertDontSee('secret-statement.pdf')
        ->call('linkTo', $theirs->id, $mine->id)
        ->call('acceptSuggestion', $theirs->id)
        ->call('rejectSuggestion', $theirs->id)
        ->call('unlink', $theirs->id)
        ->call('startEditing', $theirs->id)
        ->call('delete', $theirs->id)
        ->assertHasErrors('statement');

    expect(AuditEvent::query()->where('action', 'livewire.statement-vault.delete')->sole()->outcome)->toBe(AuditOutcome::Failure);

    $this->actingAs($this->user)->get(route('statements.download', $theirs->id))->assertNotFound();

    $fresh = $theirs->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh->account_id)->toBeNull()
        ->and($fresh->review_status)->toBe(StatementReviewStatus::Unmatched)
        ->and($fresh->suggested_account_id)->toBe($theirAccount->id)
        ->and(Storage::disk('local')->exists('statements/theirs.pdf'))->toBeTrue();
});

it('refuses to link a statement to another user\'s account', function () {
    $foreign = Account::factory()->for(User::factory()->create())->create();
    $statement = Statement::factory()->for($this->user)->create();

    vaultFor($this->user)->call('linkTo', $statement->id, $foreign->id)->assertHasErrors('link.'.$statement->id);

    expect($statement->fresh()->account_id)->toBeNull();
});

it('asks for an account when linking without one', function () {
    $statement = Statement::factory()->for($this->user)->create();

    vaultFor($this->user)->call('linkTo', $statement->id, null)->assertHasErrors('link.'.$statement->id);
});

it('keeps rejected statements in the inbox so they can be deleted', function () {
    Storage::disk('local')->put('statements/rejected.pdf', '%PDF-1.4');
    $account = Account::factory()->for($this->user)->create();
    $statement = Statement::factory()->for($this->user)->create([
        'stored_path' => 'statements/rejected.pdf',
        'original_filename' => 'rejected.pdf',
        'suggested_account_id' => $account->id,
        'suggestion_confidence' => '0.5000',
    ]);

    $component = vaultFor($this->user)->call('rejectSuggestion', $statement->id);

    expect($statement->fresh()->review_status)->toBe(StatementReviewStatus::Rejected)
        ->and($statement->fresh()->suggested_account_id)->toBeNull();

    $component->assertSee('rejected.pdf')->assertSeeHtml('statement-rejected-'.$statement->id)->call('delete', $statement->id);

    expect(Statement::query()->whereKey($statement->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists('statements/rejected.pdf'))->toBeFalse();
});

it('rejects a period end before its start', function () {
    $statement = Statement::factory()->for($this->user)->create(['institution_hint' => 'Kept']);

    vaultFor($this->user)
        ->call('startEditing', $statement->id)
        ->set('institutionHint', 'Changed')
        ->set('periodStart', '2026-03-31')
        ->set('periodEnd', '2026-03-01')
        ->call('saveDetails')
        ->assertHasErrors('period_end')
        ->assertSee('The period end field must be a date after or equal to period start.');

    expect($statement->fresh()->institution_hint)->toBe('Kept')
        ->and($statement->fresh()->period_end)->toBeNull();
});

it('saves the details form in cents', function () {
    $statement = Statement::factory()->for($this->user)->create();

    vaultFor($this->user)
        ->call('startEditing', $statement->id)
        ->set('institutionHint', 'Westpac')
        ->set('periodStart', '2026-03-01')
        ->set('periodEnd', '2026-03-31')
        ->set('openingBalance', '1234.56')
        ->set('closingBalance', '-20.00')
        ->set('currency', 'aud')
        ->call('saveDetails')
        ->assertHasNoErrors();

    $fresh = $statement->fresh();

    expect($fresh->institution_hint)->toBe('Westpac')
        ->and($fresh->opening_balance)->toBe(123456)
        ->and($fresh->closing_balance)->toBe(-2000)
        ->and($fresh->currency)->toBe('AUD');
});

it('rejects a non-numeric balance', function () {
    $statement = Statement::factory()->for($this->user)->create();

    vaultFor($this->user)
        ->call('startEditing', $statement->id)
        ->set('openingBalance', 'abc')
        ->call('saveDetails')
        ->assertHasErrors('openingBalance');

    expect($statement->fresh()->opening_balance)->toBeNull();
});

it('totals storage used across the user\'s own statements only', function () {
    Statement::factory()->for($this->user)->create(['byte_size' => 1024 * 1024]);
    Statement::factory()->for($this->user)->create(['byte_size' => 2 * 1024 * 1024]);
    Statement::factory()->for(User::factory()->create())->create(['byte_size' => 5 * 1024 * 1024]);

    vaultFor($this->user)->assertSee('Storage used: 3 MB');
});

it('uploads files into the inbox and reports duplicates and errors once', function () {
    $csv = "Date,Description,Amount\n2026-03-03,Coffee,-4.50\n";
    $component = vaultFor($this->user)
        ->set('uploads', [vaultCsv('march.csv', $csv), vaultCsv('again.csv', $csv), vaultCsv('bad.pdf', "Date,Amount\n")])
        ->call('upload');

    $component->assertSee('1 duplicate statement was skipped.')
        ->assertSee('bad.pdf');

    expect(Statement::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and($component->get('uploads'))->toBe([]);
});

it('uploads straight onto a chosen account', function () {
    $account = Account::factory()->for($this->user)->create();

    vaultFor($this->user)
        ->set('uploads', [vaultCsv('march.csv', "Date,Amount\n2026-03-03,1.00\n")])
        ->set('uploadAccountId', $account->id)
        ->call('upload');

    expect(Statement::query()->sole()->account_id)->toBe($account->id);
});

it('refuses to upload onto another user\'s account', function () {
    $foreign = Account::factory()->for(User::factory()->create())->create();

    vaultFor($this->user)
        ->set('uploads', [vaultCsv('march.csv', "Date,Amount\n2026-03-03,1.00\n")])
        ->set('uploadAccountId', $foreign->id)
        ->call('upload')
        ->assertHasErrors('uploadAccountId');

    expect(Statement::query()->count())->toBe(0);
});

it('requires a file to upload', function () {
    vaultFor($this->user)->call('upload')->assertHasErrors('uploads');
});

it('paginates the inbox', function () {
    Statement::factory()->for($this->user)->count(11)->create();

    $component = vaultFor($this->user);

    expect($component->instance()->inbox->total())->toBe(11)
        ->and($component->instance()->inbox->count())->toBe(10);
});

it('downloads the owner\'s own statement file', function () {
    Storage::disk('local')->put('statements/mine.pdf', '%PDF-1.4 mine');
    $statement = Statement::factory()->for($this->user)->create(['stored_path' => 'statements/mine.pdf', 'original_filename' => 'mine.pdf']);

    $response = $this->actingAs($this->user)->get(route('statements.download', $statement->id));

    $response->assertOk()->assertDownload('mine.pdf');
});

it('answers 404 when the stored file is missing', function () {
    $statement = Statement::factory()->for($this->user)->create(['stored_path' => 'statements/gone.pdf']);

    $this->actingAs($this->user)->get(route('statements.download', $statement->id))->assertNotFound();
});

it('sends guests to log in', function () {
    $statement = Statement::factory()->create();

    $this->get(route('statements'))->assertRedirect(route('login'));
    $this->get(route('statements.download', $statement->id))->assertRedirect(route('login'));
});

it('renders the statements page for a signed-in user', function () {
    $this->actingAs($this->user)->get(route('statements'))->assertOk()->assertSeeLivewire(StatementVault::class);
});

it('steps back to the last page when the only row on the current page is removed', function () {
    $statements = Statement::factory()->for($this->user)->count(11)->create();

    $component = vaultFor($this->user)->call('gotoPage', 2, 'inboxPage');

    expect($component->instance()->inbox->count())->toBe(1);

    $component->call('delete', $statements->first()->id);

    expect($component->instance()->inbox->currentPage())->toBe(1)
        ->and($component->instance()->inbox->count())->toBe(10);
});

it('records a successful link as a success after an earlier failed upload', function () {
    $account = Account::factory()->for($this->user)->create();
    $statement = Statement::factory()->for($this->user)->create();

    vaultFor($this->user)
        ->set('uploads', [vaultCsv('bad.pdf', "Date,Amount\n")])
        ->call('upload')
        ->call('linkTo', $statement->id, $account->id);

    expect(AuditEvent::query()->where('action', 'livewire.statement-vault.linkTo')->sole()->outcome)->toBe(AuditOutcome::Success);
});

it('clears the queued files when too many are picked and lets them be cleared', function () {
    $files = array_map(fn (int $i) => vaultCsv("s{$i}.csv", "Date,Amount\n2026-01-01,{$i}.00\n"), range(1, 21));

    $component = vaultFor($this->user)->set('uploads', $files)->call('upload');

    $component->assertHasErrors('uploads');

    expect($component->get('uploads'))->toBe([])
        ->and(Statement::query()->count())->toBe(0);

    $component->set('uploads', [vaultCsv('one.csv', "Date,Amount\n2026-01-01,1.00\n")])->assertSee('1 file ready to upload')->call('clearUploads');

    expect($component->get('uploads'))->toBe([]);
});

it('lets temporary uploads reach the statement size limit', function () {
    expect(config('livewire.temporary_file_upload.rules'))->toContain('max:25600');
});
