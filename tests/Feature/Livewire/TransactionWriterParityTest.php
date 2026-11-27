<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ExclusionSource;
use App\Enums\StaleVersion;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Exceptions\TransactionWriteRefusedException;
use App\Livewire\TransactionList;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transactions\TransactionWriteOptions;
use App\Services\Transactions\TransactionWriter;
use Livewire\Livewire;
use Tests\Concerns\CallsApi;

uses(CallsApi::class);

beforeEach(function (): void {
    $this->travelTo('2026-10-15');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->token = $this->apiTokenFor($this->user);
});

function writerRow(object $test, array $overrides = []): Transaction
{
    return Transaction::factory()->manual()->create($overrides + [
        'user_id' => $test->user->id,
        'account_id' => $test->account->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'description' => 'WRITER '.fake()->unique()->numerify('####'),
        'post_date' => '2026-10-10',
        'transfer_pair_id' => null,
    ]);
}

function writerTransferLeg(object $test, array $overrides = []): Transaction
{
    $other = Account::factory()->for($test->user)->create();
    $leg = writerRow($test, $overrides);
    $partner = writerRow($test, ['account_id' => $other->id, 'direction' => TransactionDirection::Credit, 'description' => $leg->description, 'transfer_pair_id' => $leg->id]);
    $leg->update(['transfer_pair_id' => $partner->id]);

    return $leg;
}

function writerSplitParent(object $test): Transaction
{
    $parent = writerRow($test, ['excluded' => true, 'amount' => 5000]);
    writerRow($test, ['amount' => 3000, 'split_parent_id' => $parent->id]);
    writerRow($test, ['amount' => 2000, 'split_parent_id' => $parent->id]);

    return $parent;
}

it('applies the same exclusion rules from the modal, the list and the API', function () {
    $parent = writerSplitParent($this);
    $leg = writerTransferLeg($this);
    $ruleRow = writerRow($this, ['excluded' => true, 'excluded_source' => ExclusionSource::Rule]);
    $plain = writerRow($this);

    expect(TransactionWriter::exclusionRefusal($parent, false))->toBe('A split transaction stays excluded until the split is undone.')
        ->and(TransactionWriter::exclusionRefusal($leg, true))->toBe('A transfer leg cannot be excluded.')
        ->and(TransactionWriter::exclusionRefusal($parent, true))->toBeNull()
        ->and(TransactionWriter::exclusionRefusal($plain, true))->toBeNull()
        ->and(TransactionWriter::exclusionRefusal($plain, false))->toBeNull();

    $this->apiCall('PATCH', 'transactions/'.$parent->id, $this->token, ['excluded' => false])->assertUnprocessable();
    $this->apiCall('PATCH', 'transactions/'.$leg->id, $this->token, ['excluded' => true])->assertUnprocessable();
    $apiKept = $this->apiCall('PATCH', 'transactions/'.$ruleRow->id, $this->token, ['excluded' => true, 'notes' => 'n'])->assertOk()->json('data.id');

    expect(Transaction::query()->findOrFail($apiKept)->excluded_source)->toBe(ExclusionSource::Rule);

    $list = Livewire::actingAs($this->user)->test(TransactionList::class);
    $list->call('toggleExcluded', $parent->id, false)->call('toggleExcluded', $leg->id, true);

    expect(Transaction::findCurrentVersion($parent->id, $this->user->id)->id)->toBe($parent->id)
        ->and(Transaction::findCurrentVersion($leg->id, $this->user->id)->id)->toBe($leg->id);

    $list->call('toggleExcluded', $plain->id, true);
    $excluded = Transaction::findCurrentVersion($plain->id, $this->user->id);

    expect($excluded->excluded)->toBeTrue()->and($excluded->excluded_source)->toBe(ExclusionSource::Manual);

    $list->call('toggleExcluded', $excluded->id, false);

    expect(Transaction::findCurrentVersion($plain->id, $this->user->id)->excluded_source)->toBe(ExclusionSource::Manual);

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $parent->id)->set('excluded', false)->call('save')->assertHasErrors('descriptionInput');

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $ruleRow->id)->set('notes', 'edited')->call('save')->assertHasNoErrors();

    expect(Transaction::findCurrentVersion($ruleRow->id, $this->user->id)->excluded_source)->toBe(ExclusionSource::Rule)
        ->and(Transaction::findCurrentVersion($parent->id, $this->user->id)->excluded)->toBeTrue();
});

it('refuses edits to split parents the same way from the web and the API', function () {
    $apiParent = writerSplitParent($this);
    $webParent = writerSplitParent($this);

    $this->apiCall('PATCH', 'transactions/'.$apiParent->id, $this->token, ['amount' => 7000])->assertUnprocessable();

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $webParent->id)
        ->set('descriptionInput', '70.00 Changed')->call('save')
        ->assertHasErrors('descriptionInput');

    $writerRefusal = null;

    try {
        app(TransactionWriter::class)->update($this->user, $webParent->id, ['amount' => 7000], new TransactionWriteOptions(allowImportedCoreEdits: true));
    } catch (TransactionWriteRefusedException $refused) {
        $writerRefusal = $refused->getMessage();
    }

    expect($writerRefusal)->not->toBeNull()
        ->and(Transaction::findCurrentVersion($apiParent->id, $this->user->id)->id)->toBe($apiParent->id)
        ->and(Transaction::findCurrentVersion($webParent->id, $this->user->id)->id)->toBe($webParent->id);
});

it('lets the modal edit an imported csv row while the API refuses it', function () {
    $api = writerRow($this, ['source' => TransactionSource::Csv]);
    $web = writerRow($this, ['source' => TransactionSource::Csv]);
    $service = writerRow($this, ['source' => TransactionSource::Csv]);

    $this->apiCall('PATCH', 'transactions/'.$api->id, $this->token, ['amount' => 7000])->assertUnprocessable();

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $web->id)
        ->set('descriptionInput', '70.00 Changed')->call('save')->assertHasNoErrors();

    $edited = app(TransactionWriter::class)->update($this->user, $service->id, ['amount' => 7000], new TransactionWriteOptions(allowImportedCoreEdits: true));

    expect(Transaction::findCurrentVersion($web->id, $this->user->id)->amount)->toBe(7000)
        ->and($edited->amount)->toBe(7000)
        ->and(Transaction::findCurrentVersion($api->id, $this->user->id)->amount)->toBe(5000)
        ->and(fn () => app(TransactionWriter::class)->update($this->user, writerRow($this, ['source' => TransactionSource::Csv])->id, ['amount' => 7000]))->toThrow(TransactionWriteRefusedException::class);
});

it('refuses a stale form edit in the modal and follows the current version in the API', function () {
    $apiRow = writerRow($this);
    $apiCurrent = $apiRow->createChild(['notes' => 'edited elsewhere']);
    $writer = app(TransactionWriter::class);

    $viaApi = $this->apiCall('PATCH', 'transactions/'.$apiCurrent->id, $this->token, ['notes' => 'from the API'])->assertOk()->json('data.id');
    $this->apiCall('PATCH', 'transactions/'.$apiRow->id, $this->token, ['notes' => 'old id'])->assertNotFound();

    expect(Transaction::query()->findOrFail($viaApi)->notes)->toBe('from the API')
        ->and(fn () => $writer->update($this->user, $apiRow->id, ['notes' => 'stale'], new TransactionWriteOptions(stale: StaleVersion::Refuse)))->toThrow(TransactionWriteRefusedException::class)
        ->and($writer->update($this->user, $viaApi, ['notes' => 'followed'], new TransactionWriteOptions(stale: StaleVersion::Follow))->notes)->toBe('followed');

    $row = writerRow($this);
    $component = Livewire::actingAs($this->user)->test(TransactionModal::class)->dispatch('edit-transaction', id: $row->id);

    $current = $row->createChild(['notes' => 'edited elsewhere']);

    $component->set('notes', 'from the stale form')->call('save')->assertHasErrors('descriptionInput');

    expect(Transaction::findCurrentVersion($row->id, $this->user->id)->id)->toBe($current->id)
        ->and($current->fresh()->notes)->toBe('edited elsewhere');
});

it('moves a row with category lines to another account from the modal and keeps its lines', function () {
    $other = Account::factory()->for($this->user)->create();
    $row = writerRow($this);
    $row->splits()->createMany([
        ['category_id' => App\Models\Category::factory()->for($this->user)->create()->id, 'amount' => 3000, 'position' => 0],
        ['category_id' => App\Models\Category::factory()->for($this->user)->create()->id, 'amount' => 2000, 'position' => 1],
    ]);

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->set('accountId', $other->id)->call('save')
        ->assertHasNoErrors();

    $current = Transaction::findCurrentVersion($row->id, $this->user->id);

    expect($current->id)->not->toBe($row->id)
        ->and($current->account_id)->toBe($other->id)
        ->and($current->splits()->count())->toBe(2);
});

it('says the transaction changed when the modal deletes a row that was removed meanwhile', function () {
    $row = writerRow($this);
    $component = Livewire::actingAs($this->user)->test(TransactionModal::class)->dispatch('edit-transaction', id: $row->id);

    $row->delete();

    $component->call('deleteTransaction')->assertHasErrors('descriptionInput');
});
