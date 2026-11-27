<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ApiKeyScope;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Tests\Concerns\CallsApi;

uses(CallsApi::class);

it('creates a manual transaction in cents for the token owner', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['currency' => 'AUD']);
    $category = Category::factory()->create(['user_id' => $user->id]);
    $token = $this->apiTokenFor($user);

    $response = $this->apiCall('POST', 'transactions', $token, [
        'account_id' => $account->id,
        'amount' => 1234,
        'direction' => 'debit',
        'post_date' => '2026-05-10',
        'description' => 'Coffee',
        'category_id' => $category->id,
        'notes' => 'with Sam',
    ])->assertCreated();

    $row = Transaction::query()->sole();

    expect($response->json('data.amount'))->toBe(1234)
        ->and($row->user_id)->toBe($user->id)
        ->and($row->source)->toBe(TransactionSource::Manual)
        ->and($row->currency)->toBe('AUD')
        ->and($row->category_id)->toBe($category->id);
});

it('records an http audit event against the token owner for an API write', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $other = User::factory()->create();

    $this->apiCall('POST', 'transactions', $this->apiTokenFor($user), [
        'account_id' => $account->id, 'amount' => 500, 'direction' => 'credit', 'post_date' => '2026-05-10', 'description' => 'Refund',
    ])->assertCreated();

    $event = AuditEvent::query()->where('action', 'http.api.v1.transactions.store')->sole();

    expect($event->user_id)->toBe($user->id)->and($event->outcome->value)->toBe('success')
        ->and(AuditEvent::query()->where('user_id', $other->id)->count())->toBe(0);
});

it('audits a refused API write as a failure against the token owner', function () {
    $user = User::factory()->create();

    $this->apiCall('POST', 'transactions', $this->apiTokenFor($user), [])->assertUnprocessable();

    $event = AuditEvent::query()->where('action', 'http.api.v1.transactions.store')->sole();

    expect($event->user_id)->toBe($user->id)->and($event->outcome->value)->toBe('failure');
});

it('does not audit a request that never authenticated', function () {
    config(['budget.api' => true]);

    $this->postJson('/api/v1/transactions', [])->assertUnauthorized();

    expect(AuditEvent::query()->count())->toBe(0);
});

it('refuses a write with a read-only key as insufficient scope', function (string $method, string $uri) {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->create();
    $token = $this->apiTokenFor($user, ApiKeyScope::Read);

    $uri = str_replace('{id}', (string) $transaction->id, $uri);

    $this->apiCall($method, $uri, $token, ['account_id' => $account->id, 'amount' => 1, 'direction' => 'debit', 'post_date' => '2026-01-01', 'description' => 'x'])
        ->assertForbidden()
        ->assertJsonPath('message', 'insufficient scope');

    expect(Transaction::query()->count())->toBe(1)->and($transaction->fresh()->deleted_at)->toBeNull();
})->with([
    'create' => ['POST', 'transactions'],
    'update' => ['PATCH', 'transactions/{id}'],
    'replace' => ['PUT', 'transactions/{id}'],
    'delete' => ['DELETE', 'transactions/{id}'],
    'splits' => ['PUT', 'transactions/{id}/splits'],
    'unsplit' => ['DELETE', 'transactions/{id}/splits'],
    'sync' => ['POST', 'feeds/sync'],
]);

it('lets a read-only key still read', function () {
    $user = User::factory()->create();

    $this->apiCall('GET', 'transactions', $this->apiTokenFor($user, ApiKeyScope::Read))->assertOk();
});

it('refuses a write when the key scope says read-write but the abilities do not include write', function () {
    $user = User::factory()->create();
    $token = $this->apiTokenFor($user);
    App\Models\ApiKey::query()->where('user_id', $user->id)->update(['abilities' => json_encode(['read'])]);

    $this->apiCall('POST', 'transactions', $token, [])->assertForbidden();
});

it('validates a new transaction and refuses another user\'s account or category', function (array $override) {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $foreignAccount = Account::factory()->create();
    $foreignCategory = Category::factory()->create();

    $payload = array_replace(['account_id' => $account->id, 'amount' => 100, 'direction' => 'debit', 'post_date' => '2026-05-10', 'description' => 'ok'], str_replace(['{foreignAccount}', '{foreignCategory}'], [(string) $foreignAccount->id, (string) $foreignCategory->id], $override));

    if (isset($payload['account_id']) && $payload['account_id'] === (string) $foreignAccount->id) {
        $payload['account_id'] = $foreignAccount->id;
    }

    if (isset($payload['category_id'])) {
        $payload['category_id'] = (int) $payload['category_id'];
    }

    $this->apiCall('POST', 'transactions', $this->apiTokenFor($user), $payload)->assertUnprocessable();

    expect(Transaction::query()->count())->toBe(0);
})->with([
    'zero amount' => [['amount' => 0]],
    'negative amount' => [['amount' => -5]],
    'decimal amount' => [['amount' => 12.5]],
    'bad direction' => [['direction' => 'sideways']],
    'bad date' => [['post_date' => '10/05/2026']],
    'no description' => [['description' => '']],
    'foreign account' => [['account_id' => '{foreignAccount}']],
    'foreign category' => [['category_id' => '{foreignCategory}']],
]);

it('updates the category, notes and excluded flag of a bank row as a new version', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $row = Transaction::factory()->for($user)->for($account)->debit()->create(['amount' => 900, 'source' => TransactionSource::Redbark]);

    $response = $this->apiCall('PATCH', 'transactions/'.$row->id, $this->apiTokenFor($user), ['category_id' => $category->id, 'notes' => 'checked', 'excluded' => true])->assertOk();

    $child = Transaction::query()->findOrFail($response->json('data.id'));

    expect($child->parent_transaction_id)->toBe($row->id)
        ->and($child->category_id)->toBe($category->id)
        ->and($child->notes)->toBe('checked')
        ->and($child->excluded)->toBeTrue()
        ->and($child->amount)->toBe(900)
        ->and($child->locked_fields)->toContain('category_id');
});

it('refuses to change the amount of an imported bank transaction', function () {
    $user = User::factory()->create();
    $row = Transaction::factory()->for($user)->debit()->create(['amount' => 900, 'source' => TransactionSource::Redbark]);

    $this->apiCall('PATCH', 'transactions/'.$row->id, $this->apiTokenFor($user), ['amount' => 1])->assertUnprocessable();

    expect($row->fresh()->amount)->toBe(900)->and(Transaction::query()->count())->toBe(1);
});

it('lets a manual transaction change amount, direction, date and description', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $row = Transaction::factory()->for($user)->for($account)->debit()->create(['amount' => 900, 'source' => TransactionSource::Manual]);

    $data = $this->apiCall('PATCH', 'transactions/'.$row->id, $this->apiTokenFor($user), ['amount' => 1500, 'direction' => 'credit', 'post_date' => '2026-02-02', 'description' => 'Fixed'])->assertOk()->json('data');

    expect($data['amount'])->toBe(1500)->and($data['direction'])->toBe('credit')->and($data['post_date'])->toBe('2026-02-02')->and($data['description'])->toBe('Fixed');
});

it('returns 404 when updating another user\'s transaction', function () {
    $user = User::factory()->create();
    $theirs = Transaction::factory()->create(['amount' => 777, 'notes' => null]);

    $this->apiCall('PATCH', 'transactions/'.$theirs->id, $this->apiTokenFor($user), ['notes' => 'hacked'])->assertNotFound();

    expect($theirs->fresh()->notes)->toBeNull()->and(Transaction::query()->count())->toBe(1);
});

it('returns 404 when deleting another user\'s transaction', function () {
    $user = User::factory()->create();
    $theirs = Transaction::factory()->create(['source' => TransactionSource::Manual]);

    $this->apiCall('DELETE', 'transactions/'.$theirs->id, $this->apiTokenFor($user))->assertNotFound();

    expect($theirs->fresh()->deleted_at)->toBeNull();
});

it('deletes a manual transaction of the token owner', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $row = Transaction::factory()->for($user)->for($account)->create(['source' => TransactionSource::Manual]);

    $this->apiCall('DELETE', 'transactions/'.$row->id, $this->apiTokenFor($user))->assertNoContent();

    expect(Transaction::query()->find($row->id))->toBeNull();
});

it('refuses to delete an imported bank transaction', function () {
    $user = User::factory()->create();
    $row = Transaction::factory()->for($user)->create(['source' => TransactionSource::Redbark]);

    $this->apiCall('DELETE', 'transactions/'.$row->id, $this->apiTokenFor($user))->assertUnprocessable();

    expect(Transaction::query()->find($row->id))->not->toBeNull();
});

it('saves and clears category lines on a transaction', function () {
    $user = User::factory()->create();
    $row = Transaction::factory()->for($user)->debit()->create(['amount' => 1000]);
    $a = Category::factory()->create(['user_id' => $user->id]);
    $b = Category::factory()->create(['user_id' => $user->id]);
    $token = $this->apiTokenFor($user);

    $lines = $this->apiCall('PUT', 'transactions/'.$row->id.'/splits', $token, ['lines' => [
        ['category_id' => $a->id, 'amount' => 600], ['category_id' => $b->id, 'amount' => 400, 'notes' => 'half'],
    ]])->assertOk()->json('data.splits');

    expect($lines)->toHaveCount(2)->and($lines[0]['amount'])->toBe(600);

    $this->apiCall('DELETE', 'transactions/'.$row->id.'/splits', $token)->assertOk()->assertJsonPath('data.splits', []);
});

it('refuses category lines that do not add up, are single, or use a foreign category', function (array $lines) {
    $user = User::factory()->create();
    $row = Transaction::factory()->for($user)->debit()->create(['amount' => 1000]);
    $mine = Category::factory()->create(['user_id' => $user->id]);
    $foreign = Category::factory()->create();

    $lines = array_map(fn (array $line): array => ['category_id' => $line['category_id'] === 'foreign' ? $foreign->id : $mine->id, 'amount' => $line['amount']], $lines);

    $this->apiCall('PUT', 'transactions/'.$row->id.'/splits', $this->apiTokenFor($user), ['lines' => $lines])->assertUnprocessable();

    expect($row->splits()->count())->toBe(0);
})->with([
    'sum short' => [[['category_id' => 'mine', 'amount' => 500], ['category_id' => 'mine2', 'amount' => 400]]],
    'single line' => [[['category_id' => 'mine', 'amount' => 1000]]],
    'foreign category' => [[['category_id' => 'foreign', 'amount' => 500], ['category_id' => 'mine', 'amount' => 500]]],
    'zero line' => [[['category_id' => 'mine', 'amount' => 1000], ['category_id' => 'mine', 'amount' => 0]]],
]);

it('returns 404 for another user\'s transaction splits', function () {
    $user = User::factory()->create();
    $theirs = Transaction::factory()->debit()->create(['amount' => 1000]);
    $c = Category::factory()->create(['user_id' => $user->id]);

    $this->apiCall('PUT', 'transactions/'.$theirs->id.'/splits', $this->apiTokenFor($user), ['lines' => [['category_id' => $c->id, 'amount' => 500], ['category_id' => $c->id, 'amount' => 500]]])->assertNotFound();
    $this->apiCall('DELETE', 'transactions/'.$theirs->id.'/splits', $this->apiTokenFor($user))->assertNotFound();
});

it('keeps the manual exclusion source when a row is excluded or included through the API', function () {
    $user = User::factory()->create();
    $row = Transaction::factory()->for($user)->debit()->create(['source' => TransactionSource::Redbark]);
    $token = $this->apiTokenFor($user);

    $excluded = $this->apiCall('PATCH', 'transactions/'.$row->id, $token, ['excluded' => true])->assertOk()->json('data.id');
    expect(Transaction::query()->findOrFail($excluded)->excluded_source)->toBe(App\Enums\ExclusionSource::Manual);

    $included = $this->apiCall('PATCH', 'transactions/'.$excluded, $token, ['excluded' => false])->assertOk()->json('data.id');
    $fresh = Transaction::query()->findOrFail($included);

    expect($fresh->excluded)->toBeFalse()->and($fresh->excluded_source)->toBe(App\Enums\ExclusionSource::Manual);
});

it('refuses to include a split parent or exclude a transfer leg', function () {
    $user = User::factory()->create();
    $token = $this->apiTokenFor($user);
    $parent = Transaction::factory()->for($user)->debit()->create(['amount' => 1000, 'excluded' => true]);
    Transaction::factory()->for($user)->debit()->create(['amount' => 600, 'split_parent_id' => $parent->id]);
    $leg = Transaction::factory()->for($user)->debit()->create();
    $partner = Transaction::factory()->for($user)->credit()->create(['transfer_pair_id' => $leg->id]);
    $leg->update(['transfer_pair_id' => $partner->id]);

    $this->apiCall('PATCH', 'transactions/'.$parent->id, $token, ['excluded' => false])->assertUnprocessable();
    $this->apiCall('PATCH', 'transactions/'.$leg->id, $token, ['excluded' => true])->assertUnprocessable();

    expect($parent->fresh()->excluded)->toBeTrue()->and($leg->fresh()->excluded)->toBeFalse();
});

it('carries category lines onto the new version of an edited transaction', function () {
    $user = User::factory()->create();
    $token = $this->apiTokenFor($user);
    $row = Transaction::factory()->for($user)->debit()->create(['amount' => 1000]);
    $a = Category::factory()->create(['user_id' => $user->id]);
    $b = Category::factory()->create(['user_id' => $user->id]);

    $this->apiCall('PUT', 'transactions/'.$row->id.'/splits', $token, ['lines' => [['category_id' => $a->id, 'amount' => 600], ['category_id' => $b->id, 'amount' => 400]]])->assertOk();

    $newId = $this->apiCall('PATCH', 'transactions/'.$row->id, $token, ['notes' => 'later'])->assertOk()->json('data.id');

    expect(Transaction::query()->findOrFail($newId)->splits()->count())->toBe(2);

    $this->apiCall('PATCH', 'transactions/'.$newId, $token, ['amount' => 5])->assertUnprocessable();
});

it('refuses a new transaction on an untracked account or in a hidden category', function () {
    $user = User::factory()->create();
    $token = $this->apiTokenFor($user);
    $untracked = Account::factory()->for($user)->create(['is_tracked' => false]);
    $tracked = Account::factory()->for($user)->create();
    $hidden = Category::factory()->create(['user_id' => $user->id, 'is_hidden' => true]);
    $base = ['amount' => 100, 'direction' => 'debit', 'post_date' => '2026-05-10', 'description' => 'x'];

    $this->apiCall('POST', 'transactions', $token, $base + ['account_id' => $untracked->id])->assertUnprocessable();
    $this->apiCall('POST', 'transactions', $token, $base + ['account_id' => $tracked->id, 'category_id' => $hidden->id])->assertUnprocessable();

    expect(Transaction::query()->count())->toBe(0);
});

it('renames the display name when a manual transaction description changes', function () {
    $user = User::factory()->create();
    $row = Transaction::factory()->for($user)->debit()->create(['source' => TransactionSource::Manual, 'description' => 'Old', 'clean_description' => 'Old', 'clean_description_source' => 'derived']);

    $id = $this->apiCall('PATCH', 'transactions/'.$row->id, $this->apiTokenFor($user), ['description' => 'New name'])->assertOk()->json('data.id');
    $child = Transaction::query()->findOrFail($id);

    expect($child->description)->toBe('New name');
    if ($row->cleanNameFollowsDescription()) {
        expect($child->clean_description)->toBe('New name');
    }
});

it('keeps a rule exclusion source and creates no version when excluded is unchanged', function () {
    $user = User::factory()->create();
    $token = $this->apiTokenFor($user);
    $row = Transaction::factory()->for($user)->debit()->create(['source' => TransactionSource::Redbark, 'excluded' => true, 'excluded_source' => App\Enums\ExclusionSource::Rule]);

    $id = $this->apiCall('PATCH', 'transactions/'.$row->id, $token, ['excluded' => true])->assertOk()->json('data.id');

    expect($id)->toBe($row->id)
        ->and(Transaction::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($row->fresh()->excluded_source)->toBe(App\Enums\ExclusionSource::Rule);

    $edited = $this->apiCall('PATCH', 'transactions/'.$row->id, $token, ['excluded' => true, 'notes' => 'n'])->assertOk()->json('data.id');

    expect(Transaction::query()->findOrFail($edited)->excluded_source)->toBe(App\Enums\ExclusionSource::Rule);
});

it('carries linked emails onto the new version of an edited transaction', function () {
    $user = User::factory()->create();
    $row = Transaction::factory()->for($user)->debit()->create(['amount' => 1000]);
    App\Models\TransactionEmail::factory()->create(['user_id' => $user->id, 'transaction_id' => $row->id, 'gmail_message_id' => 'api-receipt@mail']);

    $newId = $this->apiCall('PATCH', 'transactions/'.$row->id, $this->apiTokenFor($user), ['notes' => 'later'])->assertOk()->json('data.id');

    expect(Transaction::query()->findOrFail($newId)->emails()->pluck('gmail_message_id')->all())->toBe(['api-receipt@mail']);
});
