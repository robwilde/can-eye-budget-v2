<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Category;
use App\Models\User;
use App\Models\UserRule;
use App\Services\MonthEndBalanceRuleProvisioner;

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('the landing page links to registration when registration is enabled', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee(route('register'), false);
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('a newly registered user is provisioned with the month-end balance rule', function () {
    Category::create([
        'name' => MonthEndBalanceRuleProvisioner::CATEGORY_NAME,
        'icon' => 'building-library',
        'is_hidden' => false,
    ]);

    $this->post(route('register.store'), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'jane@example.com')->sole();

    expect(UserRule::query()->where('user_id', $user->id)->count())->toBe(1);
});
