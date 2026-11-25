<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\FileUploadController;

test('the profile page uploads a photo and shows it in the sidebar', function () {
    Storage::fake('public');
    Storage::fake(FileUploadConfiguration::disk());
    $user = User::factory()->create();
    $path = tempnam(sys_get_temp_dir(), 'avatar-').'.png';
    file_put_contents($path, UploadedFile::fake()->image('me.png', 64, 64)->getContent());

    $this->actingAs($user);

    try {
        $signedPath = app(FileUploadController::class)->validateAndStore(
            [new UploadedFile($path, 'me.png', 'image/png', null, true)],
            FileUploadConfiguration::disk(),
        )[0];

        $page = visit('/settings/profile');

        $page->script(sprintf(<<<'JS'
            (() => {
                const open = XMLHttpRequest.prototype.open;
                const send = XMLHttpRequest.prototype.send;
                XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                    this.__livewireUpload = String(url).includes('upload-file');
                    return open.call(this, method, url, ...rest);
                };
                XMLHttpRequest.prototype.send = function (body) {
                    if (! this.__livewireUpload) {
                        return send.call(this, body);
                    }
                    Object.defineProperty(this, 'status', { value: 200 });
                    Object.defineProperty(this, 'response', { value: JSON.stringify({ paths: [%s] }) });
                    setTimeout(() => this.dispatchEvent(new Event('load')));
                };
            })()
            JS, json_encode($signedPath)));

        $page->attach('[data-test="profile-photo-input"]', $path);

        $page->script(<<<'JS'
            new Promise((resolve) => {
                const component = Livewire.all().find((c) => c.name === 'pages::settings.profile');
                const timer = setInterval(() => {
                    if (component.$wire.photo) {
                        clearInterval(timer);
                        resolve(true);
                    }
                }, 50);
            })
            JS);

        $page->click('[data-test="save-photo-button"]')
            ->wait(2)
            ->assertSee('Photo updated.')
            ->screenshot(filename: 'profile-photo')
            ->assertNoJavaScriptErrors();
    } finally {
        @unlink($path);
    }

    expect($user->fresh()->avatar_path)->toStartWith('avatars/'.$user->id.'/');
});

test('the profile page resets the user data behind a password', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    $this->actingAs($user);

    $page = visit('/settings/profile');

    $page->click('[data-test="reset-data-button"]')
        ->fill('[data-test="reset-data-password"]', 'wrong-password')
        ->click('[data-test="confirm-reset-data-button"]')
        ->wait(1)
        ->assertSee('The password is incorrect')
        ->screenshot(filename: 'reset-data-modal')
        ->fill('[data-test="reset-data-password"]', 'password')
        ->click('[data-test="confirm-reset-data-button"]')
        ->wait(2)
        ->assertPathIs('/dashboard')
        ->assertSee('Your data has been reset.')
        ->assertNoJavaScriptErrors();

    expect(Account::query()->where('user_id', $user->id)->count())->toBe(0);
});
