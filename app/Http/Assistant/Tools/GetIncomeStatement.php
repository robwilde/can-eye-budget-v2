<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Contracts\AssistantTool;
use App\Enums\TransactionDirection;
use App\Models\Category;
use App\Models\User;
use App\Services\Reports\ReportAggregator;
use Carbon\CarbonImmutable;

final class GetIncomeStatement implements AssistantTool
{
    use ReadsUserData;

    public const int MAX_MONTHS = 36;

    public const int MAX_CATEGORIES = 50;

    public function __construct(private readonly ReportAggregator $reports) {}

    public function name(): string
    {
        return 'get_income_statement';
    }

    public function description(): string
    {
        return 'Income and spending per month for the user, with a per-category breakdown, in integer cents. A transfer between a tracked and an untracked account counts as spend or income; a transfer between two tracked accounts does not. Returns at most 36 months, the most recent ones. Omit the dates for the last 12 months.';
    }

    public function requiresPreview(): bool
    {
        return false;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'First day, YYYY-MM-DD.'],
                'to' => ['type' => 'string', 'description' => 'Last day, YYYY-MM-DD.'],
                'all_time' => ['type' => 'boolean', 'description' => 'Cover all history (still capped to the latest 36 months).'],
            ],
        ];
    }

    public function handle(User $user, array $arguments): array
    {
        $valid = $this->validated($arguments, [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...(is_string($arguments['from'] ?? null) ? ['after_or_equal:from'] : [])],
            'all_time' => ['sometimes', 'boolean'],
        ]);

        if (is_string($valid)) {
            return ['error' => $valid];
        }

        $today = $user->today();
        $allTime = (bool) ($valid['all_time'] ?? false);
        $end = isset($valid['to']) ? CarbonImmutable::parse($valid['to']) : $today;
        $start = match (true) {
            $allTime => null,
            isset($valid['from']) => CarbonImmutable::parse($valid['from']),
            default => $end->subMonthsNoOverflow(11)->startOfMonth(),
        };

        $atoms = $this->reports->atoms($user, 'actual', $start, $allTime && ! isset($valid['to']) ? null : $end);
        $monthly = $this->reports->netRefunds($atoms, true);

        $months = [];

        foreach ($monthly as $atom) {
            $months[$atom['ym']] ??= ['month' => $atom['ym'], 'income' => 0, 'spend' => 0, 'net' => 0];
            $key = $atom['direction'] === TransactionDirection::Debit->value ? 'spend' : 'income';
            $months[$atom['ym']][$key] += $atom['total'];
        }

        ksort($months);
        $truncated = count($months) > self::MAX_MONTHS;
        $months = array_slice($months, -self::MAX_MONTHS);
        $kept = array_column($months, 'month');

        foreach ($months as &$month) {
            $month['net'] = $month['income'] - $month['spend'];
        }
        unset($month);

        $categories = [];

        foreach ($this->reports->netRefunds(array_values(array_filter($atoms, static fn (array $atom): bool => in_array($atom['ym'], $kept, true))), false) as $atom) {
            $key = $atom['category_id'] ?? 0;
            $categories[$key] ??= ['category_id' => $atom['category_id'], 'spend' => 0, 'income' => 0];
            $categories[$key][$atom['direction'] === TransactionDirection::Debit->value ? 'spend' : 'income'] += $atom['total'];
        }

        $names = Category::query()->where('user_id', $user->id)->whereIn('id', array_filter(array_keys($categories)))->pluck('name', 'id');
        $rows = array_map(static fn (array $row): array => $row + ['name' => $row['category_id'] === null ? 'Uncategorised' : ($names[$row['category_id']] ?? 'Uncategorised')], array_values($categories));
        usort($rows, static fn (array $a, array $b): int => ($b['spend'] + $b['income']) <=> ($a['spend'] + $a['income']));

        return [
            'currency' => $user->base_currency,
            'months' => array_values($months),
            'truncated' => $truncated,
            'categories' => array_slice($rows, 0, self::MAX_CATEGORIES),
        ];
    }
}
