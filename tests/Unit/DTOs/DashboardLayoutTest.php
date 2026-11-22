<?php

declare(strict_types=1);

use App\DTOs\DashboardLayout;
use App\DTOs\UserPreferences;
use App\Enums\ChartGranularity;
use App\Enums\ChartHeight;
use App\Enums\DashboardWidget;
use App\Enums\WidgetWidth;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

function widgetKeys(array $widgets): array
{
    return array_map(static fn (DashboardWidget $widget): string => $widget->value, $widgets);
}

test('a new layout lists every widget in registry order, ready to render', function () {
    $layout = new DashboardLayout;

    expect($layout->ordered())->toBe(DashboardWidget::cases())
        ->and($layout->visible())->toBe(DashboardWidget::cases())
        ->and($layout->hiddenWidgets())->toBe([])
        ->and($layout->twoColumn)->toBeTrue()
        ->and($layout->width(DashboardWidget::BudgetsThisCycle))->toBe(WidgetWidth::Half)
        ->and($layout->width(DashboardWidget::PaySummary))->toBe(WidgetWidth::Full)
        ->and($layout->height(DashboardWidget::NetWorth))->toBe(ChartHeight::Auto);
});

test('unknown keys and bad values in a saved layout are ignored', function () {
    $layout = DashboardLayout::fromStored([
        'order' => ['recent-spending', 'nonsense', 'recent-spending', 42, 'pay-summary'],
        'hidden' => ['net-worth', 'gone', null],
        'collapsed' => 'not-a-list',
        'widths' => ['pay-summary' => 'half', 'net-worth' => 'huge', 'gone' => 'full', 3 => 'full'],
        'heights' => ['net-worth' => 'tall', 'pay-summary' => 'enormous'],
        'twoColumn' => 'yes',
        'removedKey' => true,
    ]);

    expect($layout->order)->toBe(['recent-spending', 'pay-summary'])
        ->and($layout->hidden)->toBe(['net-worth'])
        ->and($layout->collapsed)->toBe([])
        ->and($layout->widths)->toBe(['pay-summary' => 'half'])
        ->and($layout->heights)->toBe(['net-worth' => 'tall'])
        ->and($layout->twoColumn)->toBeTrue();
});

test('a widget missing from the saved order is appended, except the insights feed which goes first', function () {
    $layout = DashboardLayout::fromStored(['order' => ['recent-spending', 'pay-summary']]);
    $keys = widgetKeys($layout->ordered());

    expect($keys[0])->toBe('insights')
        ->and(array_slice($keys, 1, 2))->toBe(['recent-spending', 'pay-summary'])
        ->and($keys)->toHaveCount(count(DashboardWidget::cases()))
        ->and(end($keys))->toBe('monthly-projection');
});

test('hiding keeps the order of the rest and lists the widget as hidden', function () {
    $layout = (new DashboardLayout)->hide(DashboardWidget::NetWorth)->hide(DashboardWidget::Insights);

    expect(widgetKeys($layout->visible()))->not->toContain('net-worth')->not->toContain('insights')
        ->and(widgetKeys($layout->hiddenWidgets()))->toBe(['insights', 'net-worth'])
        ->and(widgetKeys($layout->show(DashboardWidget::NetWorth)->visible()))->toContain('net-worth');
});

test('collapsing toggles and hiding clears the collapsed flag', function () {
    $layout = (new DashboardLayout)->toggleCollapsed(DashboardWidget::NetWorth);

    expect($layout->isCollapsed(DashboardWidget::NetWorth))->toBeTrue()
        ->and($layout->toggleCollapsed(DashboardWidget::NetWorth)->isCollapsed(DashboardWidget::NetWorth))->toBeFalse()
        ->and($layout->hide(DashboardWidget::NetWorth)->isCollapsed(DashboardWidget::NetWorth))->toBeFalse();
});

test('moving a widget places it among the visible widgets and hidden ones keep trailing', function () {
    $layout = (new DashboardLayout)->hide(DashboardWidget::Insights)->move(DashboardWidget::RecentSpending, 0);

    expect(widgetKeys($layout->visible())[0])->toBe('recent-spending')
        ->and(widgetKeys($layout->ordered()))->toHaveCount(count(DashboardWidget::cases()))
        ->and(widgetKeys($layout->hiddenWidgets()))->toBe(['insights'])
        ->and((new DashboardLayout)->move(DashboardWidget::PaySummary, 99)->visible()[count(DashboardWidget::cases()) - 1])->toBe(DashboardWidget::PaySummary)
        ->and($layout->hide(DashboardWidget::NetWorth)->move(DashboardWidget::NetWorth, 0)->isHidden(DashboardWidget::NetWorth))->toBeTrue();
});

test('width, height and the two-column flag are kept per layout', function () {
    $layout = (new DashboardLayout)
        ->withWidth(DashboardWidget::PaySummary, WidgetWidth::Half)
        ->withHeight(DashboardWidget::NetWorth, ChartHeight::Tall)
        ->withTwoColumn(false);

    expect($layout->width(DashboardWidget::PaySummary))->toBe(WidgetWidth::Half)
        ->and($layout->height(DashboardWidget::NetWorth))->toBe(ChartHeight::Tall)
        ->and($layout->twoColumn)->toBeFalse();
});

test('a layout survives the preferences round trip', function () {
    $layout = (new DashboardLayout)->hide(DashboardWidget::NetWorth)->withWidth(DashboardWidget::PaySummary, WidgetWidth::Half)->move(DashboardWidget::RecentSpending, 0);
    $stored = json_decode(json_encode(UserPreferences::from(['dashboardLayout' => $layout->toArray()])->toArray()), true);
    $read = UserPreferences::fromStored($stored)->dashboardLayout;

    expect($read->order)->toBe($layout->order)
        ->and($read->hidden)->toBe(['net-worth'])
        ->and($read->width(DashboardWidget::PaySummary))->toBe(WidgetWidth::Half);
});

test('chart granularity is monthly over five years, weekly over one year, daily otherwise', function () {
    $start = CarbonImmutable::parse('2024-01-01');

    expect(ChartGranularity::resolve($start, CarbonImmutable::parse('2026-01-01')))->toBe(ChartGranularity::Weekly)
        ->and(ChartGranularity::resolve($start, CarbonImmutable::parse('2024-06-01')))->toBe(ChartGranularity::Daily)
        ->and(ChartGranularity::resolve($start, CarbonImmutable::parse('2025-01-01')))->toBe(ChartGranularity::Daily)
        ->and(ChartGranularity::resolve($start, CarbonImmutable::parse('2025-01-02')))->toBe(ChartGranularity::Weekly)
        ->and(ChartGranularity::resolve($start, CarbonImmutable::parse('2029-01-01')))->toBe(ChartGranularity::Weekly)
        ->and(ChartGranularity::resolve($start, CarbonImmutable::parse('2029-01-02')))->toBe(ChartGranularity::Monthly)
        ->and(ChartGranularity::resolve($start, CarbonImmutable::parse('2025-01-01')->endOfDay()))->toBe(ChartGranularity::Daily)
        ->and(ChartGranularity::resolve($start, CarbonImmutable::parse('2029-01-01')->endOfDay()))->toBe(ChartGranularity::Weekly);
});
