<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Actions\Fortify\CreateNewUser;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\User;
use App\Models\UserRule;
use App\Services\MonthEndBalanceRuleProvisioner;
use App\Services\UserCategoryProvisioner;
use Illuminate\Support\Facades\Event;

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
        ->assertRedirect(route('connect-bank', absolute: false));

    $this->assertAuthenticated();
});

test('registering over JSON returns 201 without a redirect', function () {
    $this->postJson(route('register.store'), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertCreated();
});

it('provisions a category tree for a new user', function () {
    $this->post(route('register.store'), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'jane@example.com')->sole();
    $balance = Category::query()->where('user_id', $user->id)->whereNull('parent_id')->where('name', MonthEndBalanceRuleProvisioner::CATEGORY_NAME)->sole();
    $rule = UserRule::query()->where('user_id', $user->id)->sole();

    expect(Category::query()->where('user_id', $user->id)->count())->toBeGreaterThan(80)
        ->and(Category::query()->whereNull('user_id')->orWhere('user_id', '!=', $user->id)->count())->toBe(0)
        ->and(CategoryGroup::query()->where('user_id', $user->id)->count())->toBe(count(UserCategoryProvisioner::GROUPS))
        ->and($balance->is_hidden)->toBeFalse()
        ->and($rule->actions[0]['value'])->toBe((string) $balance->id);

    Event::listen('eloquent.creating: '.Category::class, static function (Category $category): void {
        if ($category->name === MonthEndBalanceRuleProvisioner::CATEGORY_NAME) {
            throw new RuntimeException('Provisioning failed');
        }
    });

    try {
        expect(fn () => app(CreateNewUser::class)->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]))->toThrow(RuntimeException::class);
    } finally {
        Event::forget('eloquent.creating: '.Category::class);
    }

    expect(User::query()->where('email', 'john@example.com')->exists())->toBeFalse()
        ->and(Category::query()->where('user_id', '!=', $user->id)->count())->toBe(0)
        ->and(CategoryGroup::query()->where('user_id', '!=', $user->id)->count())->toBe(0);
});
