<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Livewire\AccountManager;
use App\Livewire\TransactionList;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->transaction = Transaction::factory()->for($this->user)->for($this->account)->manual()->create(['post_date' => now()->toDateString()]);
});

function attachmentOn(Transaction $transaction, string $name = 'receipt.pdf'): TransactionAttachment
{
    $path = 'transaction-attachments/'.$transaction->id.'/'.fake()->uuid().'.pdf';
    Storage::disk('local')->put($path, 'file');

    return TransactionAttachment::factory()->create([
        'transaction_id' => $transaction->id,
        'disk' => 'local',
        'path' => $path,
        'original_name' => $name,
    ]);
}

function editModal(User $user, Transaction $transaction)
{
    return Livewire::actingAs($user)->test(TransactionModal::class)->dispatch('edit-transaction', id: $transaction->id);
}

test('attaches a receipt to the transaction being edited', function () {
    editModal($this->user, $this->transaction)
        ->set('newAttachments', [UploadedFile::fake()->create('lunch.pdf', 200, 'application/pdf'), UploadedFile::fake()->image('till.jpg')])
        ->call('attachFiles')
        ->assertHasNoErrors();

    $attachments = TransactionAttachment::query()->where('transaction_id', $this->transaction->id)->get();

    expect($attachments)->toHaveCount(2)
        ->and($attachments->pluck('original_name')->all())->toContain('lunch.pdf', 'till.jpg')
        ->and($attachments->every(fn ($a) => Storage::disk('local')->exists($a->path)))->toBeTrue();
});

test('rejects an eleventh attachment', function () {
    foreach (range(1, 10) as $i) {
        attachmentOn($this->transaction, "r{$i}.pdf");
    }

    editModal($this->user, $this->transaction)
        ->set('newAttachments', [UploadedFile::fake()->create('eleventh.pdf', 100, 'application/pdf')])
        ->call('attachFiles')
        ->assertHasErrors(['newAttachments']);

    expect(TransactionAttachment::query()->where('transaction_id', $this->transaction->id)->count())->toBe(10);
});

test('rejects oversized or unsupported attachment files', function (string $name, int $kilobytes, string $mime) {
    editModal($this->user, $this->transaction)
        ->set('newAttachments', [UploadedFile::fake()->create($name, $kilobytes, $mime)])
        ->call('attachFiles')
        ->assertHasErrors();

    expect(TransactionAttachment::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('transaction-attachments'))->toBe([]);
})->with([
    'a 12 MB pdf' => ['big.pdf', 12288, 'application/pdf'],
    'a zip' => ['archive.zip', 10, 'application/zip'],
]);

test('carries attachments to a new transaction version', function () {
    $first = attachmentOn($this->transaction, 'a.pdf');
    $second = attachmentOn($this->transaction, 'b.pdf');

    $child = $this->transaction->createChild(['notes' => 'edited']);

    expect($child->attachments()->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and(Storage::disk('local')->exists($first->path))->toBeTrue();
});

test('keeps attachments through an edit made in the modal', function () {
    attachmentOn($this->transaction);

    editModal($this->user, $this->transaction)->set('notes', 'new note')->call('save');

    $child = Transaction::query()->where('parent_transaction_id', $this->transaction->id)->sole();

    expect($child->attachments()->count())->toBe(1)
        ->and($this->transaction->attachments()->count())->toBe(0);
});

test('cannot download another users attachment', function () {
    $other = User::factory()->create();
    $theirs = attachmentOn(Transaction::factory()->for($other)->for(Account::factory()->for($other))->manual()->create());
    $mine = attachmentOn($this->transaction, 'mine.pdf');

    $this->actingAs($this->user)->get(route('transactions.attachments.download', $theirs->id))->assertNotFound();
    $this->actingAs($this->user)->get(route('transactions.attachments.download', $mine->id))->assertOk()->assertDownload('mine.pdf');
});

test('requires a login to download an attachment', function () {
    $mine = attachmentOn($this->transaction);

    $this->get(route('transactions.attachments.download', $mine->id))->assertRedirect();
});

test('records an audit event when an attachment is added', function () {
    editModal($this->user, $this->transaction)
        ->set('newAttachments', [UploadedFile::fake()->create('lunch.pdf', 100, 'application/pdf')])
        ->call('attachFiles');

    $event = AuditEvent::query()->where('action', 'livewire.transaction-modal.attachFiles')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});

test('records an audit failure when an attachment is refused', function () {
    editModal($this->user, $this->transaction)
        ->set('newAttachments', [UploadedFile::fake()->create('archive.zip', 10, 'application/zip')])
        ->call('attachFiles');

    expect(AuditEvent::query()->where('action', 'livewire.transaction-modal.attachFiles')->sole()->outcome)->toBe(AuditOutcome::Failure);
});

test('removes an attachment and its file', function () {
    $attachment = attachmentOn($this->transaction);

    editModal($this->user, $this->transaction)->call('removeAttachment', $attachment->id);

    expect(TransactionAttachment::query()->whereKey($attachment->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($attachment->path))->toBeFalse();
});

test('cannot remove an attachment of a transaction that is not being edited', function () {
    $other = User::factory()->create();
    $theirs = attachmentOn(Transaction::factory()->for($other)->for(Account::factory()->for($other))->manual()->create());
    $sibling = attachmentOn(Transaction::factory()->for($this->user)->for($this->account)->manual()->create());

    editModal($this->user, $this->transaction)
        ->call('removeAttachment', $theirs->id)
        ->call('removeAttachment', $sibling->id);

    expect(TransactionAttachment::query()->count())->toBe(2)
        ->and(Storage::disk('local')->exists($theirs->path))->toBeTrue();
});

test('deletes the files of a deleted transaction', function () {
    $attachment = attachmentOn($this->transaction);

    editModal($this->user, $this->transaction)->call('deleteTransaction');

    expect(Transaction::query()->whereKey($this->transaction->id)->exists())->toBeFalse()
        ->and(TransactionAttachment::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($attachment->path))->toBeFalse();
});

test('hands receipts back to the previous version when the newest version is deleted', function () {
    $attachment = attachmentOn($this->transaction);

    editModal($this->user, $this->transaction)->set('notes', 'edited')->call('save');
    $child = Transaction::query()->where('parent_transaction_id', $this->transaction->id)->sole();

    editModal($this->user, $child)->call('deleteTransaction');

    expect(Transaction::query()->current()->whereKey($this->transaction->id)->exists())->toBeTrue()
        ->and($this->transaction->attachments()->pluck('id')->all())->toBe([$attachment->id])
        ->and(Storage::disk('local')->exists($attachment->path))->toBeTrue();
});

test('hands receipts back to the previous version when the newest version is force deleted', function () {
    $attachment = attachmentOn($this->transaction);
    $child = $this->transaction->createChild(['notes' => 'edited']);

    $child->getConnection()->transaction(fn () => $child->forceDelete());

    expect($this->transaction->attachments()->pluck('id')->all())->toBe([$attachment->id])
        ->and(Storage::disk('local')->exists($attachment->path))->toBeTrue();
});

test('deletes receipts with the newest version when the previous version stays superseded', function () {
    $attachment = attachmentOn($this->transaction);
    $child = $this->transaction->createChild(['notes' => 'edited']);
    $this->transaction->delete();

    $child->delete();

    expect(TransactionAttachment::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($attachment->path))->toBeFalse();
});

test('keeps the files of an edited transaction when its superseded version is soft deleted', function () {
    $attachment = attachmentOn($this->transaction);
    $child = $this->transaction->createChild(['notes' => 'edited']);

    $this->transaction->delete();

    expect($child->attachments()->count())->toBe(1)
        ->and(Storage::disk('local')->exists($attachment->path))->toBeTrue();
});

test('lists only transactions with receipts when asked, and bulk select-all respects it', function () {
    $withReceipt = $this->transaction;
    $without = Transaction::factory()->for($this->user)->for($this->account)->manual()->create(['post_date' => now()->toDateString()]);
    attachmentOn($withReceipt);
    $category = Category::factory()->create(['is_hidden' => false]);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('period', 'this-month')
        ->set('attachments', 'with');

    expect($component->viewData('transactions')->pluck('id')->all())->toBe([$withReceipt->id]);

    $component
        ->call('selectAllMatching')
        ->set('bulkCategoryId', (string) $category->id)
        ->call('applyCategoryToSelection');

    expect($withReceipt->fresh()->category_id)->toBe($category->id)
        ->and($without->fresh()->category_id)->toBeNull();
});

test('ignores an unknown attachments filter value', function () {
    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('attachments', 'garbage')
        ->assertSet('attachments', 'all');
});

test('keeps the file when the surrounding database transaction rolls back', function () {
    $attachment = attachmentOn($this->transaction);

    try {
        DB::transaction(function () {
            $this->transaction->delete();

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect(Transaction::query()->whereKey($this->transaction->id)->exists())->toBeTrue()
        ->and(TransactionAttachment::query()->whereKey($attachment->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($attachment->path))->toBeTrue();
});

test('refuses to attach a receipt to a superseded version', function () {
    $modal = editModal($this->user, $this->transaction);

    $child = $this->transaction->createChild(['notes' => 'edited elsewhere']);

    $modal->set('newAttachments', [UploadedFile::fake()->create('late.pdf', 100, 'application/pdf')])
        ->call('attachFiles')
        ->assertHasErrors(['newAttachments']);

    expect(TransactionAttachment::query()->count())->toBe(0)
        ->and($child->attachments()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('transaction-attachments'))->toBe([]);
});

test('keeps the chosen files and stores nothing when the disk write fails', function () {
    $modal = editModal($this->user, $this->transaction)
        ->set('newAttachments', [UploadedFile::fake()->create('lunch.pdf', 100, 'application/pdf')]);

    $real = Storage::disk('local');
    $failing = Mockery::mock($real)->makePartial();
    $failing->shouldReceive('writeStream')->andReturn(false);
    Storage::set('local', $failing);

    $modal->call('attachFiles')->assertHasErrors(['newAttachments'])->assertSet('newAttachments', fn (array $files) => count($files) === 1);

    expect(TransactionAttachment::query()->count())->toBe(0);
});

test('removes files already written when a later file in the batch fails', function () {
    $modal = editModal($this->user, $this->transaction)
        ->set('newAttachments', [
            UploadedFile::fake()->create('first.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->create('second.pdf', 100, 'application/pdf'),
        ]);

    $real = Storage::disk('local');
    $calls = 0;
    $failing = Mockery::mock($real)->makePartial();
    $failing->shouldReceive('writeStream')->andReturnUsing(function (...$arguments) use ($real, &$calls) {
        return ++$calls === 1 ? $real->writeStream(...$arguments) : false;
    });
    Storage::set('local', $failing);

    $modal->call('attachFiles')->assertHasErrors(['newAttachments']);

    expect($calls)->toBe(2)
        ->and(TransactionAttachment::query()->count())->toBe(0)
        ->and($real->allFiles('transaction-attachments'))->toBe([]);
});

test('tells the list to refresh when a receipt is removed', function () {
    $attachment = attachmentOn($this->transaction);

    editModal($this->user, $this->transaction)
        ->call('removeAttachment', $attachment->id)
        ->assertDispatched('transaction-saved');
});

test('deletes the files of a force deleted transaction', function () {
    $attachment = attachmentOn($this->transaction);

    $this->transaction->getConnection()->transaction(fn () => $this->transaction->forceDelete());

    expect(Transaction::withTrashed()->whereKey($this->transaction->id)->exists())->toBeFalse()
        ->and(TransactionAttachment::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($attachment->path))->toBeFalse();
});

test('labels the receipt picker for assistive technology', function () {
    editModal($this->user, $this->transaction)->assertSeeHtml('aria-label="Receipts"');
});

test('deletes the receipt files of every transaction of a deleted account', function () {
    $kept = Account::factory()->for($this->user)->create();
    $keptTransaction = Transaction::factory()->for($this->user)->for($kept)->manual()->create(['post_date' => now()->toDateString()]);
    $own = attachmentOn($this->transaction);
    $other = attachmentOn($keptTransaction);

    Livewire::actingAs($this->user)->test(AccountManager::class)
        ->set('deletingAccountId', $this->account->id)
        ->call('delete');

    expect(Account::query()->whereKey($this->account->id)->exists())->toBeFalse()
        ->and(TransactionAttachment::query()->pluck('id')->all())->toBe([$other->id])
        ->and(Storage::disk('local')->exists($own->path))->toBeFalse()
        ->and(Storage::disk('local')->exists($other->path))->toBeTrue();
});

test('refuses with an error when the edited transaction was deleted elsewhere', function () {
    $modal = editModal($this->user, $this->transaction);

    $this->transaction->delete();

    $modal->set('newAttachments', [UploadedFile::fake()->create('late.pdf', 100, 'application/pdf')])
        ->call('attachFiles')
        ->assertHasErrors(['newAttachments']);

    expect(AuditEvent::query()->where('action', 'livewire.transaction-modal.attachFiles')->sole()->outcome)->toBe(AuditOutcome::Failure);
});

test('tells the list to refresh when a receipt is attached', function () {
    editModal($this->user, $this->transaction)
        ->set('newAttachments', [UploadedFile::fake()->create('lunch.pdf', 100, 'application/pdf')])
        ->call('attachFiles')
        ->assertDispatched('transaction-saved');
});

test('cannot attach to, list or remove receipts of another users transaction by changing the edited id', function () {
    $other = User::factory()->create();
    $theirTransaction = Transaction::factory()->for($other)->for(Account::factory()->for($other))->manual()->create();
    $theirs = attachmentOn($theirTransaction, 'secret.pdf');

    editModal($this->user, $this->transaction)
        ->set('editingTransactionId', $theirTransaction->id)
        ->set('newAttachments', [UploadedFile::fake()->create('mine.pdf', 100, 'application/pdf')])
        ->call('attachFiles')
        ->call('removeAttachment', $theirs->id)
        ->assertDontSee('secret.pdf');

    expect(TransactionAttachment::query()->where('transaction_id', $theirTransaction->id)->pluck('id')->all())->toBe([$theirs->id])
        ->and(Storage::disk('local')->allFiles('transaction-attachments/'.$theirTransaction->id))->toBe([$theirs->path]);
});

test('deletes the receipt files of every transaction of a deleted user', function () {
    $own = attachmentOn($this->transaction);
    $other = User::factory()->create();
    $theirs = attachmentOn(Transaction::factory()->for($other)->for(Account::factory()->for($other))->manual()->create());

    $this->user->delete();

    expect(TransactionAttachment::query()->pluck('id')->all())->toBe([$theirs->id])
        ->and(Storage::disk('local')->exists($own->path))->toBeFalse()
        ->and(Storage::disk('local')->exists($theirs->path))->toBeTrue();
});

test('keeps the receipt files when the account delete rolls back', function () {
    $attachment = attachmentOn($this->transaction);

    try {
        DB::transaction(function () {
            $this->account->delete();

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect(Storage::disk('local')->exists($attachment->path))->toBeTrue()
        ->and(TransactionAttachment::query()->whereKey($attachment->id)->exists())->toBeTrue();
});

test('answers 404 for an attachment id too large to be one', function () {
    $this->actingAs($this->user)->get('/transactions/attachments/99999999999999999999')->assertNotFound();
});

test('ignores an unknown attachments filter value on load', function () {
    Livewire::withQueryParams(['attachments' => 'garbage'])
        ->actingAs($this->user)
        ->test(TransactionList::class)
        ->assertSet('attachments', 'all');
});
