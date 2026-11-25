<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('public');
});

it('rejects a profile photo over 10 MB', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.profile')
        ->set('photo', UploadedFile::fake()->image('big.jpg')->size(12_288))
        ->call('savePhoto')
        ->assertHasErrors(['photo']);

    expect($user->fresh()->avatar_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('rejects a profile photo that is not JPEG or PNG', function (string $name, string $mime) {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.profile')
        ->set('photo', UploadedFile::fake()->create($name, 100, $mime))
        ->call('savePhoto')
        ->assertHasErrors(['photo']);

    expect($user->fresh()->avatar_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'gif' => ['photo.gif', 'image/gif'],
    'pdf' => ['photo.pdf', 'application/pdf'],
    'webp' => ['photo.webp', 'image/webp'],
    'a script named like an image' => ['photo.php.jpg', 'text/x-php'],
]);

it('rejects a real GIF image', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.profile')
        ->set('photo', UploadedFile::fake()->image('photo.gif'))
        ->call('savePhoto')
        ->assertHasErrors(['photo']);

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('accepts a JPEG and a PNG at the 10 MB limit', function (string $name) {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.profile')
        ->set('photo', UploadedFile::fake()->image($name)->size(10_240))
        ->call('savePhoto')
        ->assertHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh()->avatar_path)->not->toBeNull();
})->with(['one.jpg', 'one.png']);

it('stores a new profile photo and deletes the previous one', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.profile')
        ->set('photo', UploadedFile::fake()->image('first.jpg'))
        ->call('savePhoto')
        ->assertHasNoErrors();

    $first = $user->fresh()->avatar_path;
    Storage::disk('public')->assertExists($first);

    Livewire::actingAs($user->fresh())->test('pages::settings.profile')
        ->set('photo', UploadedFile::fake()->image('second.png'))
        ->call('savePhoto')
        ->assertHasNoErrors();

    $second = $user->fresh()->avatar_path;

    expect($second)->not->toBe($first)
        ->and($second)->toStartWith('avatars/'.$user->id.'/');
    Storage::disk('public')->assertExists($second);
    Storage::disk('public')->assertMissing($first);
    expect(Storage::disk('public')->allFiles())->toHaveCount(1);
});

it('removes the profile photo and its file', function () {
    $user = User::factory()->create();
    Storage::disk('public')->put('avatars/'.$user->id.'/me.png', 'png');
    $user->update(['avatar_path' => 'avatars/'.$user->id.'/me.png']);

    Livewire::actingAs($user)->test('pages::settings.profile')
        ->call('removePhoto')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh()->avatar_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('keeps another users photo when one user uploads', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Storage::disk('public')->put('avatars/'.$other->id.'/theirs.png', 'png');
    $other->update(['avatar_path' => 'avatars/'.$other->id.'/theirs.png']);

    Livewire::actingAs($user)->test('pages::settings.profile')
        ->set('photo', UploadedFile::fake()->image('mine.jpg'))
        ->call('savePhoto');

    expect($other->fresh()->avatar_path)->toBe('avatars/'.$other->id.'/theirs.png');
    Storage::disk('public')->assertExists('avatars/'.$other->id.'/theirs.png');
});

it('shows the photo in the sidebar and the initials when there is none', function () {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);

    $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('AL');

    Storage::disk('public')->put('avatars/'.$user->id.'/me.png', 'png');
    $user->update(['avatar_path' => 'avatars/'.$user->id.'/me.png']);

    $this->actingAs($user->fresh())->get(route('dashboard'))
        ->assertOk()
        ->assertSee('/storage/avatars/'.$user->id.'/me.png', false);
});

it('records an audit event when a photo is saved', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.profile')
        ->set('photo', UploadedFile::fake()->image('me.jpg'))
        ->call('savePhoto');

    $event = AuditEvent::query()->where('action', 'livewire.pages::settings.profile.savePhoto')->sole();

    expect($event->user_id)->toBe($user->id)->and($event->outcome)->toBe(AuditOutcome::Success);
});

it('deletes the photo when the account is deleted', function () {
    $user = User::factory()->create();
    Storage::disk('public')->put('avatars/'.$user->id.'/me.png', 'png');
    $user->update(['avatar_path' => 'avatars/'.$user->id.'/me.png']);

    $user->delete();

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('adds a nullable avatar_path column to users', function () {
    expect(Schema::hasColumn('users', 'avatar_path'))->toBeTrue()
        ->and(User::factory()->create()->fresh()->avatar_path)->toBeNull();
});
