<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Livewire\TransactionList;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->old = Transaction::factory()->manual()->debit()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => -10000,
        'transfer_pair_id' => null,
    ]);
    $this->current = $this->old->createChild(['notes' => 'edited']);
    $this->a = Category::factory()->for($this->user)->create();
    $this->b = Category::factory()->for($this->user)->create();
});

it('does not write category lines onto a superseded version', function () {
    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->call('toggleSplit', $this->old->id)
        ->set('splitLines', [
            ['category_id' => $this->a->id, 'amount' => '70.00', 'notes' => ''],
            ['category_id' => $this->b->id, 'amount' => '30.00', 'notes' => ''],
        ]);

    expect(fn () => $component->call('saveSplit'))->toThrow(ModelNotFoundException::class)
        ->and($this->old->splits()->count())->toBe(0)
        ->and($this->current->splits()->count())->toBe(0);
});

it('does not remove category lines through a superseded version', function () {
    foreach ([$this->old, $this->current] as $row) {
        $row->splits()->createMany([
            ['category_id' => $this->a->id, 'amount' => -7000, 'position' => 0],
            ['category_id' => $this->b->id, 'amount' => -3000, 'position' => 1],
        ]);
    }

    $component = Livewire::actingAs($this->user)->test(TransactionList::class);

    expect(fn () => $component->call('unsplit', $this->old->id))->toThrow(ModelNotFoundException::class)
        ->and($this->old->splits()->count())->toBe(2)
        ->and($this->current->splits()->count())->toBe(2);
});

it('still saves and removes category lines on the current version', function () {
    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->call('toggleSplit', $this->current->id)
        ->set('splitLines', [
            ['category_id' => $this->a->id, 'amount' => '70.00', 'notes' => ''],
            ['category_id' => $this->b->id, 'amount' => '30.00', 'notes' => ''],
        ])
        ->call('saveSplit')
        ->assertSet('splitError', null);

    expect($this->current->splits()->count())->toBe(2);

    $component->call('unsplit', $this->current->id);

    expect($this->current->splits()->count())->toBe(0);
});
