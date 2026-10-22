<?php

declare(strict_types=1);

use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

test('keeps tag names unique per user', function () {
    $user = User::factory()->create();
    Tag::factory()->for($user)->create(['name' => 'Holiday']);

    expect(fn () => Tag::factory()->for($user)->create(['name' => 'Holiday']))->toThrow(QueryException::class);

    $other = Tag::factory()->for(User::factory()->create())->create(['name' => 'Holiday']);
    expect($other->exists)->toBeTrue();
});

test('carries tags to a new transaction version', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->create();
    $tags = Tag::factory()->count(2)->for($user)->create();
    $transaction->tags()->attach($tags->pluck('id'));

    $child = $transaction->createChild(['description' => 'Edited']);

    expect($child->tags()->pluck('tags.id')->sort()->values()->all())->toBe($tags->pluck('id')->sort()->values()->all())
        ->and($transaction->tags()->count())->toBe(2);
});

test('creates an untagged new version when the parent has no tags', function () {
    $child = Transaction::factory()->create()->createChild(['description' => 'Edited']);

    expect($child->tags()->count())->toBe(0);
});

test('removes only the links when a tag is deleted', function () {
    $user = User::factory()->create();
    $tag = Tag::factory()->for($user)->create();
    $transactions = Transaction::factory()->count(12)->for($user)->create();
    $tag->transactions()->attach($transactions->pluck('id'));

    $tag->delete();

    expect(Transaction::query()->whereIn('id', $transactions->pluck('id'))->count())->toBe(12)
        ->and(DB::table('tag_transaction')->count())->toBe(0);
});

test('rejects the reserved tag name and a non-hex colour', function () {
    $user = User::factory()->create();

    expect(fn () => Tag::query()->create(['user_id' => $user->id, 'name' => '__untagged__']))->toThrow(ValidationException::class)
        ->and(fn () => Tag::query()->create(['user_id' => $user->id, 'name' => 'Trip', 'colour' => 'blue']))->toThrow(ValidationException::class);
});

test('rejects a colour with a trailing newline', function () {
    expect(fn () => Tag::factory()->create(['colour' => "#AABBCC\n"]))->toThrow(ValidationException::class);
});

test('rejects a tag name longer than 50 characters', function () {
    expect(fn () => Tag::factory()->create(['name' => str_repeat('a', 51)]))->toThrow(ValidationException::class);
});

test('assigns a colour from the palette when none is given', function () {
    $tag = Tag::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Trip']);

    expect(Tag::PALETTE)->toContain($tag->colour);
});

test('keeps tag pivot index names within the MariaDB limit', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->andReturn('11.4.5-MariaDB');
    $connection = new MariaDbConnection($pdo, 'identifier_probe', '', ['name' => 'identifier_probe', 'driver' => 'mariadb']);
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());

    try {
        $queries = $connection->pretend(function () {
            (require database_path('migrations/2026_10_22_200000_create_tags_table.php'))->up();
            (require database_path('migrations/2026_10_22_200100_create_tag_transaction_table.php'))->up();
        });
    } finally {
        Schema::swap($originalSchema);
    }

    $identifiers = collect($queries)
        ->flatMap(fn (array $query) => preg_match_all('/`([^`]+)`/', $query['query'], $matches) ? $matches[1] : [])
        ->unique();

    expect($identifiers->filter(fn (string $name) => str_contains($name, 'tag_transaction_')))->not->toBeEmpty()
        ->and($identifiers->filter(fn (string $name) => mb_strlen($name) > 64)->values()->all())->toBeEmpty();
});
