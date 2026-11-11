<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Livewire\TagManager;
use App\Models\AuditEvent;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('shows each tag with the number of the users transactions that carry it', function () {
    $tag = Tag::factory()->for($this->user)->create(['name' => 'Holiday']);
    $tag->transactions()->attach(Transaction::factory()->count(3)->for($this->user)->create()->pluck('id'));
    $tag->transactions()->attach(Transaction::factory()->create()->id);

    Livewire::actingAs($this->user)
        ->test(TagManager::class)
        ->assertSee('Holiday')
        ->assertSee('3 transactions');
});

test('creates edits and rejects duplicate tag names', function () {
    Tag::factory()->for($this->user)->create(['name' => 'Travel']);

    $component = Livewire::actingAs($this->user)
        ->test(TagManager::class)
        ->call('openCreateModal')
        ->set('name', 'Holiday')
        ->set('colour', '#22C55E')
        ->call('saveTag')
        ->assertHasNoErrors();

    $tag = Tag::query()->where('user_id', $this->user->id)->where('name', 'Holiday')->sole();

    $component->call('openEditModal', $tag->id)
        ->set('name', 'Travel')
        ->call('saveTag')
        ->assertHasErrors('name')
        ->set('name', 'Trips')
        ->set('colour', '#3B82F6')
        ->call('saveTag')
        ->assertHasNoErrors();

    expect($tag->refresh()->name)->toBe('Trips')
        ->and($tag->colour)->toBe('#3B82F6');
});

test('saves an edit that keeps the same name', function () {
    $tag = Tag::factory()->for($this->user)->create(['name' => 'Holiday', 'colour' => '#22C55E']);

    Livewire::actingAs($this->user)
        ->test(TagManager::class)
        ->call('openEditModal', $tag->id)
        ->set('colour', '#3B82F6')
        ->call('saveTag')
        ->assertHasNoErrors();

    expect($tag->refresh()->name)->toBe('Holiday')
        ->and($tag->colour)->toBe('#3B82F6');
});

test('rejects the reserved name and blank names', function (string $name) {
    Livewire::actingAs($this->user)
        ->test(TagManager::class)
        ->call('openCreateModal')
        ->set('name', $name)
        ->call('saveTag')
        ->assertHasErrors('name');

    expect(Tag::query()->where('user_id', $this->user->id)->count())->toBe(0);
})->with([
    'reserved' => Tag::RESERVED_NAME,
    'blank' => '   ',
]);

test('trims the name before saving', function () {
    Livewire::actingAs($this->user)
        ->test(TagManager::class)
        ->call('openCreateModal')
        ->set('name', '  Holiday  ')
        ->call('saveTag')
        ->assertHasNoErrors();

    expect(Tag::query()->where('user_id', $this->user->id)->sole()->name)->toBe('Holiday');
});

test('moves transactions to the replacement tag on delete', function () {
    $holiday = Tag::factory()->for($this->user)->create(['name' => 'Holiday']);
    $travel = Tag::factory()->for($this->user)->create(['name' => 'Travel']);
    $transactions = Transaction::factory()->count(12)->for($this->user)->create();
    $holiday->transactions()->attach($transactions->pluck('id'));
    $travel->transactions()->attach($transactions->first()->id);

    Livewire::actingAs($this->user)
        ->test(TagManager::class)
        ->call('confirmDelete', $holiday->id)
        ->set('replacementTagId', $travel->id)
        ->call('deleteTag')
        ->assertHasNoErrors();

    expect(Tag::query()->whereKey($holiday->id)->exists())->toBeFalse()
        ->and($travel->transactions()->pluck('transactions.id')->sort()->values()->all())
        ->toBe($transactions->pluck('id')->sort()->values()->all());
});

test('deletes a tag without a replacement and keeps its transactions', function () {
    $holiday = Tag::factory()->for($this->user)->create();
    $transactions = Transaction::factory()->count(2)->for($this->user)->create();
    $holiday->transactions()->attach($transactions->pluck('id'));

    Livewire::actingAs($this->user)
        ->test(TagManager::class)
        ->call('confirmDelete', $holiday->id)
        ->call('deleteTag')
        ->assertHasNoErrors()
        ->assertSet('showDeleteModal', false);

    expect(Tag::query()->whereKey($holiday->id)->exists())->toBeFalse()
        ->and(Transaction::query()->whereKey($transactions->pluck('id'))->count())->toBe(2)
        ->and(Transaction::query()->whereKey($transactions->pluck('id'))->whereHas('tags')->count())->toBe(0);
});

test('closes the delete modal when the tag was already deleted elsewhere', function () {
    $holiday = Tag::factory()->for($this->user)->create();

    $component = Livewire::actingAs($this->user)
        ->test(TagManager::class)
        ->call('confirmDelete', $holiday->id);

    $holiday->delete();

    $component->call('deleteTag')
        ->assertHasNoErrors()
        ->assertSet('showDeleteModal', false);
});

test('does not open the edit modal for another users tag', function () {
    $foreign = Tag::factory()->for(User::factory()->create())->create(['name' => 'Secret']);

    Livewire::actingAs($this->user)
        ->test(TagManager::class)
        ->call('openEditModal', $foreign->id)
        ->assertSet('showFormModal', false)
        ->assertSet('editingTagId', null)
        ->assertSet('name', '');
});

test('does not open the delete modal for another users tag', function () {
    $foreign = Tag::factory()->for(User::factory()->create())->create(['name' => 'Secret']);

    Livewire::actingAs($this->user)
        ->test(TagManager::class)
        ->call('confirmDelete', $foreign->id)
        ->assertSet('showDeleteModal', false)
        ->assertSet('deletingTagId', null)
        ->assertSet('deletingTagName', '');

    expect(Tag::query()->whereKey($foreign->id)->exists())->toBeTrue();
});

test('refuses to replace a tag with itself', function () {
    $holiday = Tag::factory()->for($this->user)->create();
    $holiday->transactions()->attach(Transaction::factory()->for($this->user)->create()->id);

    Livewire::actingAs($this->user)
        ->test(TagManager::class)
        ->call('confirmDelete', $holiday->id)
        ->set('replacementTagId', $holiday->id)
        ->call('deleteTag')
        ->assertHasErrors('replacementTagId');

    expect(Tag::query()->whereKey($holiday->id)->exists())->toBeTrue()
        ->and($holiday->transactions()->count())->toBe(1);
});

test('refuses another users tag as a replacement', function () {
    $holiday = Tag::factory()->for($this->user)->create();
    $holiday->transactions()->attach(Transaction::factory()->for($this->user)->create()->id);
    $foreign = Tag::factory()->for(User::factory()->create())->create();

    Livewire::actingAs($this->user)
        ->test(TagManager::class)
        ->call('confirmDelete', $holiday->id)
        ->set('replacementTagId', $foreign->id)
        ->call('deleteTag')
        ->assertHasErrors('replacementTagId');

    expect(Tag::query()->whereKey($holiday->id)->exists())->toBeTrue()
        ->and($holiday->transactions()->count())->toBe(1)
        ->and($foreign->transactions()->count())->toBe(0);
});

test('deletes only the users own tags when deleting all', function () {
    Tag::factory()->count(3)->for($this->user)->create();
    $other = User::factory()->create();
    Tag::factory()->count(2)->for($other)->create();

    Livewire::actingAs($this->user)
        ->test(TagManager::class)
        ->call('confirmDeleteAll')
        ->call('deleteAllTags');

    expect(Tag::query()->where('user_id', $this->user->id)->count())->toBe(0)
        ->and(Tag::query()->where('user_id', $other->id)->count())->toBe(2);
});

test('records an audit event when a tag is replaced', function () {
    $holiday = Tag::factory()->for($this->user)->create();
    $travel = Tag::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(TagManager::class)
        ->call('confirmDelete', $holiday->id)
        ->set('replacementTagId', $travel->id)
        ->call('deleteTag');

    $event = AuditEvent::query()->where('action', 'livewire.tag-manager.deleteTag')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});

test('redirects a guest away from the tags page and serves it to a signed-in user', function () {
    $this->get(route('tags'))->assertRedirect();

    $this->actingAs($this->user)->get(route('tags'))->assertOk()->assertSeeLivewire(TagManager::class);
});

test('moves the links of deleted transactions to the replacement tag too', function () {
    $holiday = Tag::factory()->for($this->user)->create();
    $travel = Tag::factory()->for($this->user)->create();
    $transaction = Transaction::factory()->for($this->user)->create();
    $holiday->transactions()->attach($transaction->id);
    $transaction->delete();

    Livewire::actingAs($this->user)
        ->test(TagManager::class)
        ->call('confirmDelete', $holiday->id)
        ->set('replacementTagId', $travel->id)
        ->call('deleteTag');

    expect($travel->transactions()->withTrashed()->pluck('transactions.id')->all())->toBe([$transaction->id]);
});

test('marks only the selected colour as pressed', function () {
    $html = Livewire::actingAs($this->user)
        ->test(TagManager::class)
        ->call('openCreateModal')
        ->set('colour', '#22c55e')
        ->html();

    expect(mb_substr_count($html, 'aria-pressed="true"'))->toBe(1)
        ->and($html)->toMatch('/aria-label="#22C55E"\s+aria-pressed="true"/');
});
