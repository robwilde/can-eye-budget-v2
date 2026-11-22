<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\DashboardWidget;
use App\Models\Account;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use Livewire\Livewire;

function rootTag(string $html): ?string
{
    $html = (string) preg_replace('/<!--.*?-->/s', '', $html);

    return preg_match('/^\s*<([a-z][a-z0-9-]*)/i', $html, $matches) === 1 ? mb_strtolower($matches[1]) : null;
}

it('gives every lazy widget a placeholder with the same root element as its view', function () {
    $user = User::factory()->withPayCycle()->create();
    $account = Account::factory()->for($user)->create();
    $user->update(['primary_account_id' => $account->id]);

    $lazy = array_filter(DashboardWidget::cases(), static fn (DashboardWidget $widget): bool => $widget->lazy() && $widget->component() !== null);

    expect($lazy)->not->toBeEmpty();

    foreach ($lazy as $widget) {
        $params = $widget->componentParams(DashboardPeriod::for($user)->stamp());
        $placeholderRoot = rootTag(Livewire::actingAs($user)->test($widget->component(), $params)->html());
        $viewRoot = rootTag(Livewire::actingAs($user)->test($widget->component(), [...$params, 'lazy' => false])->html());

        expect($viewRoot)->not->toBeNull($widget->value.' view has no root element')
            ->and($placeholderRoot)->toBe($viewRoot, $widget->value.' placeholder root differs from its view root');
    }
});

it('gives the lazy dashboard a placeholder with the same root element as its view', function () {
    $user = User::factory()->withPayCycle()->create();

    expect(rootTag(Livewire::actingAs($user)->test('dashboard', ['lazy' => true])->html()))
        ->toBe(rootTag(Livewire::actingAs($user)->test('dashboard', ['lazy' => false])->html()));
});
