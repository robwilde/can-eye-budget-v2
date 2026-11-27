<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Exceptions\TransactionWriteRefusedException;
use App\Livewire\TransactionList;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transactions\TransactionCategoryLines;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Concerns\CallsApi;

uses(CallsApi::class);

beforeEach(function (): void {
    $this->travelTo('2026-10-15');
    $this->user = User::factory()->create();
    $this->token = $this->apiTokenFor($this->user);
    $this->category = Category::factory()->for($this->user)->create();
});

function lineParityRow(object $test, int $cents): Transaction
{
    return Transaction::factory()->manual()->debit()->create([
        'user_id' => $test->user->id,
        'account_id' => Account::factory()->for($test->user)->create()->id,
        'amount' => -$cents,
        'post_date' => '2026-10-10',
        'transfer_pair_id' => null,
    ]);
}

/** @return list<array{category_id: string, amount: string, notes: string}> */
function webLines(object $test, int $count, string $amount = '1.00'): array
{
    return array_fill(0, $count, ['category_id' => (string) $test->category->id, 'amount' => $amount, 'notes' => '']);
}

it('carries category lines onto a new version from the transaction list', function () {
    $row = lineParityRow($this, 10000);
    $other = Category::factory()->for($this->user)->create();

    $list = Livewire::actingAs($this->user)->test(TransactionList::class)
        ->call('toggleSplit', $row->id)
        ->set('splitLines', [
            ['category_id' => (string) $this->category->id, 'amount' => '70.00', 'notes' => 'shop'],
            ['category_id' => (string) $other->id, 'amount' => '30.00', 'notes' => ''],
        ])
        ->call('saveSplit')
        ->assertSet('splitError', null);

    $list->call('toggleExcluded', $row->id, true);

    $current = Transaction::findCurrentVersion($row->id, $this->user->id);

    expect($current->id)->not->toBe($row->id)
        ->and($current->splits()->orderBy('position')->pluck('amount')->all())->toBe([-7000, -3000])
        ->and($current->splits()->orderBy('position')->pluck('notes')->all())->toBe(['shop', null]);
});

it('applies the same line limit from the list and the API', function () {
    expect(TransactionCategoryLines::MAX_LINES)->toBe(50);

    $over = lineParityRow($this, 5100);
    $atLimit = lineParityRow($this, 5000);
    $viaApi = lineParityRow($this, 5000);
    $apiLines = static fn (int $count): array => array_fill(0, $count, ['category_id' => test()->category->id, 'amount' => 100]);

    $this->apiCall('PUT', 'transactions/'.$over->id.'/splits', $this->token, ['lines' => $apiLines(51)])->assertUnprocessable();
    $this->apiCall('PUT', 'transactions/'.$viaApi->id.'/splits', $this->token, ['lines' => $apiLines(50)])->assertOk();

    expect(fn () => app(TransactionCategoryLines::class)->save($this->user, $over->id, $apiLines(51)))
        ->toThrow(TransactionWriteRefusedException::class, 'A split can have at most 50 lines.');

    Livewire::actingAs($this->user)->test(TransactionList::class)
        ->call('toggleSplit', $over->id)
        ->set('splitLines', webLines($this, 51))
        ->call('saveSplit')
        ->assertSet('splitError', 'A split can have at most 50 lines.');

    Livewire::actingAs($this->user)->test(TransactionList::class)
        ->call('toggleSplit', $atLimit->id)
        ->set('splitLines', webLines($this, 50))
        ->call('saveSplit')
        ->assertSet('splitError', null);

    expect($over->splits()->count())->toBe(0)
        ->and($atLimit->splits()->count())->toBe(50)
        ->and($viaApi->splits()->count())->toBe(50);
});

it('refuses lines on a non-current version from the list and the API', function () {
    $old = lineParityRow($this, 10000);
    $current = $old->createChild(['notes' => 'edited']);

    $this->apiCall('PUT', 'transactions/'.$old->id.'/splits', $this->token, [
        'lines' => [['category_id' => $this->category->id, 'amount' => 7000], ['category_id' => $this->category->id, 'amount' => 3000]],
    ])->assertNotFound();

    $list = Livewire::actingAs($this->user)->test(TransactionList::class)
        ->call('toggleSplit', $old->id)
        ->set('splitLines', [
            ['category_id' => (string) $this->category->id, 'amount' => '70.00', 'notes' => ''],
            ['category_id' => (string) $this->category->id, 'amount' => '30.00', 'notes' => ''],
        ]);

    expect(fn () => $list->call('saveSplit'))->toThrow(ModelNotFoundException::class)
        ->and($old->splits()->count())->toBe(0)
        ->and($current->splits()->count())->toBe(0);
});
