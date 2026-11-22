<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ChartHeight;
use App\Enums\DashboardWidget;
use App\Enums\WidgetWidth;
use BackedEnum;
use Spatie\LaravelData\Dto;

/**
 * A user's dashboard layout, kept in the user preferences: widget order, hidden and collapsed
 * widgets, per-widget width and chart height, and the two-column appearance flag. Reading it
 * tolerates anything stored: unknown widget keys and bad values are ignored, and a widget that
 * is missing from the saved order is appended (the insights feed goes first).
 */
final class DashboardLayout extends Dto
{
    /**
     * @param  list<string>  $order  widget keys, first to last
     * @param  list<string>  $hidden
     * @param  list<string>  $collapsed
     * @param  array<string, string>  $widths  widget key => half|full
     * @param  array<string, string>  $heights  widget key => compact|auto|tall
     */
    public function __construct(
        public readonly array $order = [],
        public readonly array $hidden = [],
        public readonly array $collapsed = [],
        public readonly array $widths = [],
        public readonly array $heights = [],
        public readonly bool $twoColumn = true,
    ) {}

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromStored(array $stored): self
    {
        return new self(
            order: self::keys($stored['order'] ?? null),
            hidden: self::keys($stored['hidden'] ?? null),
            collapsed: self::keys($stored['collapsed'] ?? null),
            widths: self::choices($stored['widths'] ?? null, WidgetWidth::class),
            heights: self::choices($stored['heights'] ?? null, ChartHeight::class),
            twoColumn: is_bool($stored['twoColumn'] ?? null) ? $stored['twoColumn'] : true,
        );
    }

    /**
     * Every widget in display order: the saved order first, then widgets the saved order does not
     * know, in their default order, with the insights feed ahead of everything.
     *
     * @return list<DashboardWidget>
     */
    public function ordered(): array
    {
        $saved = array_values(array_filter(array_map(DashboardWidget::tryFrom(...), $this->order)));
        $missing = array_values(array_filter(DashboardWidget::cases(), static fn (DashboardWidget $widget): bool => ! in_array($widget, $saved, true)));

        $insights = in_array(DashboardWidget::Insights, $missing, true) ? [DashboardWidget::Insights] : [];
        $rest = array_values(array_filter($missing, static fn (DashboardWidget $widget): bool => $widget !== DashboardWidget::Insights));

        return [...$insights, ...$saved, ...$rest];
    }

    /** @return list<DashboardWidget> */
    public function visible(): array
    {
        return array_values(array_filter($this->ordered(), fn (DashboardWidget $widget): bool => ! $this->isHidden($widget)));
    }

    /** @return list<DashboardWidget> */
    public function hiddenWidgets(): array
    {
        return array_values(array_filter($this->ordered(), fn (DashboardWidget $widget): bool => $this->isHidden($widget)));
    }

    public function isHidden(DashboardWidget $widget): bool
    {
        return in_array($widget->value, $this->hidden, true);
    }

    public function isCollapsed(DashboardWidget $widget): bool
    {
        return in_array($widget->value, $this->collapsed, true);
    }

    public function width(DashboardWidget $widget): WidgetWidth
    {
        return WidgetWidth::tryFrom($this->widths[$widget->value] ?? '') ?? $widget->defaultWidth();
    }

    public function height(DashboardWidget $widget): ChartHeight
    {
        return ChartHeight::tryFrom($this->heights[$widget->value] ?? '') ?? ChartHeight::Auto;
    }

    public function hide(DashboardWidget $widget): self
    {
        return $this->copy(hidden: $this->without($this->hidden, $widget, add: true), collapsed: $this->without($this->collapsed, $widget));
    }

    public function show(DashboardWidget $widget): self
    {
        return $this->copy(hidden: $this->without($this->hidden, $widget));
    }

    public function toggleCollapsed(DashboardWidget $widget): self
    {
        return $this->copy(collapsed: $this->without($this->collapsed, $widget, add: ! $this->isCollapsed($widget)));
    }

    /**
     * Moves a visible widget to a zero-based position among the visible widgets; hidden widgets
     * keep their relative order after them.
     */
    public function move(DashboardWidget $widget, int $position): self
    {
        $visible = array_values(array_filter($this->visible(), static fn (DashboardWidget $other): bool => $other !== $widget));

        if ($this->isHidden($widget)) {
            return $this;
        }

        array_splice($visible, max(0, min($position, count($visible))), 0, [$widget]);

        return $this->copy(order: array_map(static fn (DashboardWidget $item): string => $item->value, [...$visible, ...$this->hiddenWidgets()]));
    }

    public function withWidth(DashboardWidget $widget, WidgetWidth $width): self
    {
        return $this->copy(widths: [...$this->widths, $widget->value => $width->value]);
    }

    public function withHeight(DashboardWidget $widget, ChartHeight $height): self
    {
        return $this->copy(heights: [...$this->heights, $widget->value => $height->value]);
    }

    public function withTwoColumn(bool $twoColumn): self
    {
        return $this->copy(twoColumn: $twoColumn);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @return list<string>
     */
    private static function keys(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $keys = [];

        foreach ($value as $key) {
            if (is_string($key) && DashboardWidget::tryFrom($key) !== null && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @return array<string, string>
     */
    private static function choices(mixed $value, string $enum): array
    {
        if (! is_array($value)) {
            return [];
        }

        $choices = [];

        foreach ($value as $key => $choice) {
            if (is_string($key) && DashboardWidget::tryFrom($key) !== null && is_string($choice) && $enum::tryFrom($choice) !== null) {
                $choices[$key] = $choice;
            }
        }

        return $choices;
    }

    /**
     * @param  list<string>|null  $order
     * @param  list<string>|null  $hidden
     * @param  list<string>|null  $collapsed
     * @param  array<string, string>|null  $widths
     * @param  array<string, string>|null  $heights
     */
    private function copy(?array $order = null, ?array $hidden = null, ?array $collapsed = null, ?array $widths = null, ?array $heights = null, ?bool $twoColumn = null): self
    {
        return new self(
            order: $order ?? array_map(static fn (DashboardWidget $widget): string => $widget->value, $this->ordered()),
            hidden: $hidden ?? $this->hidden,
            collapsed: $collapsed ?? $this->collapsed,
            widths: $widths ?? $this->widths,
            heights: $heights ?? $this->heights,
            twoColumn: $twoColumn ?? $this->twoColumn,
        );
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function without(array $keys, DashboardWidget $widget, bool $add = false): array
    {
        $kept = array_values(array_filter($keys, static fn (string $key): bool => $key !== $widget->value));

        return $add ? [...$kept, $widget->value] : $kept;
    }
}
