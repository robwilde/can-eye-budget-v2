<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ApiKeyScope;
use App\Enums\AuditOutcome;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;

uses(Tests\Concerns\CallsApi::class);

it('creates a tag for the token owner', function (): void {
    $user = User::factory()->create();
    $token = $this->apiTokenFor($user);

    $this->apiCall('POST', 'tags', $token, ['name' => '  Holiday  ', 'colour' => '#22C55E'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Holiday')
        ->assertJsonPath('data.colour', '#22C55E');

    expect(Tag::query()->where('user_id', $user->id)->pluck('name')->all())->toBe(['Holiday']);
});

it('picks a palette colour when the colour is omitted', function (): void {
    $user = User::factory()->create();

    $this->apiCall('POST', 'tags', $this->apiTokenFor($user), ['name' => 'Work'])->assertCreated();

    expect(Tag::PALETTE)->toContain(Tag::query()->where('user_id', $user->id)->sole()->colour);
});

it('rejects a duplicate tag name for the same user with 422 but allows it for another user', function (): void {
    $user = User::factory()->create();
    Tag::factory()->for($user)->create(['name' => 'Holiday']);
    $token = $this->apiTokenFor($user);

    $this->apiCall('POST', 'tags', $token, ['name' => 'Holiday'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    $other = User::factory()->create();
    $this->apiCall('POST', 'tags', $this->apiTokenFor($other), ['name' => 'Holiday'])->assertCreated();

    expect(Tag::query()->where('name', 'Holiday')->count())->toBe(2);
});

it('rejects an invalid tag name or colour with 422', function (): void {
    $token = $this->apiTokenFor(User::factory()->create());

    $this->apiCall('POST', 'tags', $token, ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->apiCall('POST', 'tags', $token, ['name' => Tag::RESERVED_NAME])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->apiCall('POST', 'tags', $token, ['name' => str_repeat('a', 51)])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->apiCall('POST', 'tags', $token, ['name' => 'Ok', 'colour' => 'red'])->assertUnprocessable()->assertJsonValidationErrors('colour');
});

it('updates a tag with PUT and PATCH', function (): void {
    $user = User::factory()->create();
    $tag = Tag::factory()->for($user)->create(['name' => 'Old', 'colour' => '#EF4444']);
    $token = $this->apiTokenFor($user);

    $this->apiCall('PUT', "tags/{$tag->id}", $token, ['name' => 'New', 'colour' => '#3B82F6'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New')
        ->assertJsonPath('data.colour', '#3B82F6');

    $this->apiCall('PATCH', "tags/{$tag->id}", $token, ['colour' => '#A855F7'])->assertOk();

    expect($tag->refresh()->name)->toBe('New')
        ->and($tag->colour)->toBe('#A855F7');
});

it('allows a tag to keep its own name on update but not take another tag name', function (): void {
    $user = User::factory()->create();
    $tag = Tag::factory()->for($user)->create(['name' => 'One']);
    Tag::factory()->for($user)->create(['name' => 'Two']);
    $token = $this->apiTokenFor($user);

    $this->apiCall('PATCH', "tags/{$tag->id}", $token, ['name' => 'One'])->assertOk();
    $this->apiCall('PATCH', "tags/{$tag->id}", $token, ['name' => 'Two'])->assertUnprocessable()->assertJsonValidationErrors('name');

    expect($tag->refresh()->name)->toBe('One');
});

it('answers 404 and leaves another user tag unchanged on update', function (): void {
    $user = User::factory()->create();
    $foreign = Tag::factory()->for(User::factory()->create())->create(['name' => 'Theirs', 'colour' => '#EF4444']);

    $this->apiCall('PUT', "tags/{$foreign->id}", $this->apiTokenFor($user), ['name' => 'Mine', 'colour' => '#3B82F6'])->assertNotFound();

    expect($foreign->refresh()->name)->toBe('Theirs')
        ->and($foreign->colour)->toBe('#EF4444');
});

it('deletes a tag without a replacement', function (): void {
    $user = User::factory()->create();
    $tag = Tag::factory()->for($user)->create();

    $this->apiCall('DELETE', "tags/{$tag->id}", $this->apiTokenFor($user))->assertNoContent();

    expect(Tag::query()->whereKey($tag->id)->exists())->toBeFalse();
});

it('moves transaction links to the replacement when deleting a tag with one', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $tag = Tag::factory()->for($user)->create();
    $replacement = Tag::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->create();
    $transaction->tags()->attach($tag->id);

    $this->apiCall('DELETE', "tags/{$tag->id}", $this->apiTokenFor($user), ['replacement_id' => $replacement->id])->assertNoContent();

    expect(Tag::query()->whereKey($tag->id)->exists())->toBeFalse()
        ->and($transaction->tags()->pluck('tags.id')->all())->toBe([$replacement->id]);
});

it('rejects a foreign or self replacement tag with 422 and keeps the tag', function (): void {
    $user = User::factory()->create();
    $tag = Tag::factory()->for($user)->create();
    $foreign = Tag::factory()->for(User::factory()->create())->create();
    $token = $this->apiTokenFor($user);

    $this->apiCall('DELETE', "tags/{$tag->id}", $token, ['replacement_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('replacement_id');
    $this->apiCall('DELETE', "tags/{$tag->id}", $token, ['replacement_id' => $tag->id])->assertUnprocessable()->assertJsonValidationErrors('replacement_id');

    expect(Tag::query()->whereKey($tag->id)->exists())->toBeTrue();
});

it('answers 404 and keeps another user tag on delete', function (): void {
    $user = User::factory()->create();
    $foreign = Tag::factory()->for(User::factory()->create())->create(['name' => 'Theirs']);

    $this->apiCall('DELETE', "tags/{$foreign->id}", $this->apiTokenFor($user))->assertNotFound();

    expect(Tag::query()->whereKey($foreign->id)->sole()->name)->toBe('Theirs');
});

it('refuses tag writes from a read-only key with 403 and changes nothing', function (): void {
    $user = User::factory()->create();
    $tag = Tag::factory()->for($user)->create(['name' => 'Keep']);
    $token = $this->apiTokenFor($user, ApiKeyScope::Read);

    $this->apiCall('POST', 'tags', $token, ['name' => 'Nope'])->assertForbidden()->assertJson(['message' => 'insufficient scope']);
    $this->apiCall('PUT', "tags/{$tag->id}", $token, ['name' => 'Nope'])->assertForbidden();
    $this->apiCall('DELETE', "tags/{$tag->id}", $token)->assertForbidden();

    expect(Tag::query()->pluck('name')->all())->toBe(['Keep']);
});

it('lists and shows only the token owner tags', function (): void {
    $user = User::factory()->create();
    $mine = Tag::factory()->for($user)->create(['name' => 'Mine']);
    $foreign = Tag::factory()->for(User::factory()->create())->create(['name' => 'Theirs']);
    $token = $this->apiTokenFor($user, ApiKeyScope::Read);

    $this->apiCall('GET', 'tags', $token)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Mine');
    $this->apiCall('GET', "tags/{$mine->id}", $token)->assertOk()->assertJsonPath('data.id', $mine->id);
    $this->apiCall('GET', "tags/{$foreign->id}", $token)->assertNotFound();
});

it('records an audit event for tag creation against the token owner', function (): void {
    $user = User::factory()->create();

    $this->apiCall('POST', 'tags', $this->apiTokenFor($user), ['name' => 'Audited'])->assertCreated();

    $event = AuditEvent::query()->where('action', 'http.api.v1.tags.store')->sole();

    expect($event->user_id)->toBe($user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});
