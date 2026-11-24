<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Enums\ReportSection;
use App\Enums\TransactionDirection;
use App\Enums\TransactionPeriod;
use App\Models\Account;
use App\Models\BudgetPlan;
use App\Models\Category;
use App\Models\Tag;
use App\Models\User;
use App\Services\Budgets\BudgetPlanCalculator;
use App\Services\Reports\InvestmentReport;
use App\Services\Reports\ReportAggregator;
use App\Services\Reports\ReportCsv;
use App\Services\Reports\ReportFilters;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Exception;
use Illuminate\View\View;
use League\Csv\Writer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CategoryReport extends Component
{
    private const array VALID_MODES = ['real', 'plan'];

    private const int TOP_LIMIT = 5;

    private const int PAGE_SIZE = 10;

    private const array VALID_SORTS = ['amount', 'count'];

    private const array FALLBACK_COLORS = [
        '#6366F1', '#8B5CF6', '#EC4899', '#F43F5E',
        '#F97316', '#EAB308', '#22C55E', '#14B8A6',
        '#06B6D4', '#3B82F6', '#A855F7', '#78716C',
    ];

    #[Url]
    public string $period = 'this-month';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    #[Url]
    public string $mode = 'real';

    #[Url]
    public bool $topOnly = false;

    #[Url]
    public bool $showSubcategories = false;

    #[Url]
    public string $account = '';

    #[Url]
    public string $tag = '';

    #[Url]
    public string $min = '';

    #[Url]
    public string $max = '';

    #[Url]
    public string $sort = 'amount';

    #[Url]
    public int $expensePage = 1;

    #[Url]
    public int $incomePage = 1;

    public bool $rangeSwapped = false;

    #[Locked]
    public bool $print = false;

    /** @var list<int> */
    public array $expanded = [];

    /** @var array<int, array{id: int, name: string, parent_id: int|null, icon: string|null, color: string|null}>|null */
    private ?array $categoryMap = null;

    /** @var array{start: CarbonInterface|null, end: CarbonInterface|null}|null */
    private ?array $datesCache = null;

    private ?ReportFilters $filtersCache = null;

    /** @var list<array{ym: string, direction: string, category_id: int|null, total: int, count: int}>|null */
    private ?array $rawAtomsCache = null;

    /** @var list<array{ym: string, direction: string, category_id: int|null, total: int, count: int}>|null */
    private ?array $monthAtomsCache = null;

    /** @var list<array{ym: string, direction: string, category_id: int|null, total: int, count: int}>|null */
    private ?array $atomsCache = null;

    /** @var array{start: CarbonImmutable, end: CarbonImmutable}|null */
    private ?array $boundsCache = null;

    private ?int $monthCountCache = null;

    /** @var array<string, array{buckets: list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, pct: float, perMonth: int, bar: int, expandable: bool, children: list<array{id: int, path: string, total: int, count: int, pct: float, perMonth: int, bar: int}>}>, sum: int, max: int}> */
    private array $bucketsCache = [];

    public function mount(bool $print = false): void
    {
        $this->print = $print;

        if ($this->period === TransactionPeriod::ThisMonth->value && request()->query('period') === null) {
            $this->period = $this->user()->defaultListPeriod();
        }

        if (! TransactionPeriod::tryFrom($this->period)) {
            $this->period = 'this-month';
        }

        if (! in_array($this->mode, self::VALID_MODES, true)) {
            $this->mode = 'real';
        }

        if (! in_array($this->sort, self::VALID_SORTS, true)) {
            $this->sort = 'amount';
        }

        $this->settleRange();
    }

    public function updatedPeriod(): void
    {
        if (! TransactionPeriod::tryFrom($this->period)) {
            $this->period = 'this-month';
        }

        $this->settleRange();
        $this->refreshCharts();
    }

    public function updatedMode(): void
    {
        if (! in_array($this->mode, self::VALID_MODES, true)) {
            $this->mode = 'real';
        }

        $this->refreshCharts();
    }

    public function updatedFrom(): void
    {
        $this->settleRange();
        $this->refreshCharts();
    }

    public function updatedTo(): void
    {
        $this->settleRange();
        $this->refreshCharts();
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['account', 'tag', 'min', 'max', 'sort'], true)) {
            return;
        }

        if (! in_array($this->sort, self::VALID_SORTS, true)) {
            $this->sort = 'amount';
        }

        $this->refreshCharts();
    }

    /**
     * Moves the report to the previous (-1) or next (1) period of the same length. A range of
     * whole calendar months steps by its own number of months; any other range steps by its
     * own number of days. Nothing later than the current period can be stepped to.
     */
    public function step(int $direction): void
    {
        $window = $this->window();

        if ($window === null) {
            return;
        }

        $direction = $direction < 0 ? -1 : 1;
        ['start' => $start, 'end' => $end] = $window;

        if ($direction > 0 && ! $this->canStepForward()) {
            return;
        }

        $wholeMonth = $start->isSameDay($start->startOfMonth())
            && ($this->period === TransactionPeriod::ThisMonth->value || $end->isSameDay($end->endOfMonth()));

        if ($wholeMonth) {
            $months = ($end->year - $start->year) * 12 + $end->month - $start->month + 1;
            $newStart = $start->addMonthsNoOverflow($direction * $months);
            $newEnd = $newStart->addMonthsNoOverflow($months - 1)->endOfMonth();
        } else {
            $days = (int) round($start->diffInDays($end)) + 1;
            $newStart = $start->addDays($direction * $days);
            $newEnd = $newStart->addDays($days - 1);
        }

        $this->period = TransactionPeriod::Custom->value;
        $this->from = $newStart->toDateString();
        $this->to = $newEnd->toDateString();
        $this->rangeSwapped = false;
        $this->refreshCharts();
    }

    /**
     * Downloads the report as CSV: one row per root category with a column for each month
     * and a total, in income and expense sections, then a net row. It honours the period,
     * mode and filters on the page, and its totals are the page's totals.
     */
    public function exportCsv(): StreamedResponse
    {
        $bounds = $this->bounds();
        $months = $this->aggregator()->monthKeys($bounds['start'], $bounds['end']);
        $rows = ReportCsv::rows(
            $months,
            $this->csvSection(TransactionDirection::Credit->value),
            $this->csvSection(TransactionDirection::Debit->value),
        );

        return response()->streamDownload(static function () use ($rows): void {
            $csv = Writer::createFromPath('php://output', 'w');
            $csv->insertAll($rows);
        }, 'report-'.$bounds['start']->format('Y-m').'-to-'.$bounds['end']->format('Y-m').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function toggleExpand(int $categoryId): void
    {
        if (in_array($categoryId, $this->expanded, true)) {
            $this->expanded = array_values(array_filter(
                $this->expanded,
                static fn (int $id): bool => $id !== $categoryId,
            ));

            return;
        }

        $this->expanded[] = $categoryId;
    }

    /**
     * @return array{in: int, out: int, net: int, months: int, inPerMonth: int, outPerMonth: int, netPerMonth: int, outPctIncome: int|null, netPctIncome: int|null, inChange: int|null, outChange: int|null, netChange: int|null}
     */
    #[Computed]
    public function summary(): array
    {
        [$in, $out] = $this->directionTotals($this->atoms());

        $months = $this->monthCount();
        $net = $in - $out;
        $prior = $this->priorTotals();

        return [
            'in' => $in,
            'out' => $out,
            'net' => $net,
            'months' => $months,
            'inPerMonth' => intdiv($in, $months),
            'outPerMonth' => intdiv($out, $months),
            'netPerMonth' => intdiv($net, $months),
            'outPctIncome' => $in > 0 ? (int) round($out / $in * 100) : null,
            'netPctIncome' => $in > 0 ? (int) round($net / $in * 100) : null,
            'inChange' => $prior === null ? null : $this->percentChange($in, $prior['in']),
            'outChange' => $prior === null ? null : $this->percentChange($out, $prior['out']),
            'netChange' => $prior === null ? null : $this->percentChange($net, $prior['in'] - $prior['out']),
        ];
    }

    /**
     * @return array{buckets: list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, pct: float, perMonth: int, bar: int, expandable: bool, children: list<array{id: int, path: string, total: int, count: int, pct: float, perMonth: int, bar: int}>}>, sum: int, max: int}
     */
    #[Computed]
    public function expenses(): array
    {
        return $this->buckets(TransactionDirection::Debit->value);
    }

    /**
     * @return array{buckets: list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, pct: float, perMonth: int, bar: int, expandable: bool, children: list<array{id: int, path: string, total: int, count: int, pct: float, perMonth: int, bar: int}>}>, sum: int, max: int}
     */
    #[Computed]
    public function incomes(): array
    {
        return $this->buckets(TransactionDirection::Credit->value);
    }

    /**
     * @return list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, pct: float, perMonth: int, bar: int, expandable: bool, children: list<array{id: int, path: string, total: int, count: int, pct: float, perMonth: int, bar: int}>}>
     */
    #[Computed]
    public function expenseRows(): array
    {
        return $this->limitBuckets($this->buckets(TransactionDirection::Debit->value)['buckets']);
    }

    /**
     * @return list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, pct: float, perMonth: int, bar: int, expandable: bool, children: list<array{id: int, path: string, total: int, count: int, pct: float, perMonth: int, bar: int}>}>
     */
    #[Computed]
    public function incomeRows(): array
    {
        return $this->limitBuckets($this->buckets(TransactionDirection::Credit->value)['buckets']);
    }

    /**
     * @return array{rows: list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, pct: float, perMonth: int, bar: int, expandable: bool, children: list<array{id: int, path: string, total: int, count: int, pct: float, perMonth: int, bar: int}>}>, page: int, pages: int}
     */
    #[Computed]
    public function expenseView(): array
    {
        return $this->paginate($this->expenseRows(), $this->expensePage);
    }

    /**
     * @return array{rows: list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, pct: float, perMonth: int, bar: int, expandable: bool, children: list<array{id: int, path: string, total: int, count: int, pct: float, perMonth: int, bar: int}>}>, page: int, pages: int}
     */
    #[Computed]
    public function incomeView(): array
    {
        return $this->paginate($this->incomeRows(), $this->incomePage);
    }

    /**
     * This month's spending against the initialised budget plan, shown only while the
     * report covers the current calendar month in real mode.
     *
     * @return array{spent: int, budget: int, percent: int}|null
     */
    #[Computed]
    public function budgetPerformance(): ?array
    {
        if ($this->mode !== 'real' || ! $this->coversCurrentMonth()) {
            return null;
        }

        $user = $this->user();

        $plan = BudgetPlan::query()
            ->where('user_id', $user->id)
            ->whereNotNull('initialised_at')
            ->whereDate('period_start', $user->today()->startOfMonth())
            ->first();

        if ($plan === null) {
            return null;
        }

        $summary = app(BudgetPlanCalculator::class)->summarise($plan);

        if ($summary->totalAmount <= 0) {
            return null;
        }

        return [
            'spent' => $summary->spent,
            'budget' => $summary->totalAmount,
            'percent' => intdiv($summary->spent * 100, $summary->totalAmount),
        ];
    }

    /**
     * @return array{labels: list<string>, income: list<int>, expense: list<int>, net: list<int>}
     */
    #[Computed]
    public function chart(): array
    {
        $bounds = $this->bounds();
        $keys = $this->aggregator()->monthKeys($bounds['start'], $bounds['end']);

        /** @var array<string, array<string, int>> $byMonth */
        $byMonth = [];

        foreach ($this->monthAtoms() as $atom) {
            $byMonth[$atom['ym']][$atom['direction']] = ($byMonth[$atom['ym']][$atom['direction']] ?? 0) + $atom['total'];
        }

        $labels = [];
        $income = [];
        $expense = [];
        $net = [];

        foreach ($keys as $key) {
            $labels[] = CarbonImmutable::parse($key.'-01')->format('M y');
            $in = $byMonth[$key][TransactionDirection::Credit->value] ?? 0;
            $out = $byMonth[$key][TransactionDirection::Debit->value] ?? 0;
            $income[] = $in;
            $expense[] = $out;
            $net[] = $in - $out;
        }

        return ['labels' => $labels, 'income' => $income, 'expense' => $expense, 'net' => $net];
    }

    /**
     * @return array{expense: list<array{x: string, y: int, color: string}>, income: list<array{x: string, y: int, color: string}>}
     */
    #[Computed]
    public function treemap(): array
    {
        return [
            'expense' => $this->treemapNodes($this->buckets(TransactionDirection::Debit->value)['buckets']),
            'income' => $this->treemapNodes($this->buckets(TransactionDirection::Credit->value)['buckets']),
        ];
    }

    /**
     * The page's sections in the user's saved order, unknown keys ignored and missing sections appended.
     * The investment sections are left out while the investments flag is off, when the user has no active
     * investment account and, for the flows, when the period has no contributions or withdrawals.
     *
     * @return list<ReportSection>
     */
    #[Computed]
    public function sections(): array
    {
        $user = $this->user();
        $span = $this->span();
        $investments = app(InvestmentReport::class);

        return array_values(array_filter(
            ReportSection::ordered($user->preferences->reportSectionOrder),
            static fn (ReportSection $section): bool => match ($section) {
                ReportSection::InvestmentPerformance => $investments->appliesTo($user),
                ReportSection::InvestmentFlows => $investments->hasFlows($user, $span['start'], $span['end']),
                default => true,
            },
        ));
    }

    /**
     * Moves a section one place up or down and saves the order for this user.
     */
    public function moveSection(string $key, string $direction): void
    {
        $section = ReportSection::tryFrom($key);
        $offset = match ($direction) {
            'up' => -1,
            'down' => 1,
            default => null,
        };

        if ($section === null || $offset === null) {
            return;
        }

        $shown = $this->sections; // @phpstan-ignore property.notFound
        $index = array_search($section, $shown, true);
        $neighbour = $index === false ? null : ($shown[$index + $offset] ?? null);

        if ($neighbour === null) {
            return;
        }

        $order = ReportSection::ordered($this->user()->preferences->reportSectionOrder);
        $from = array_search($section, $order, true);
        $to = array_search($neighbour, $order, true);

        [$order[$from], $order[$to]] = [$order[$to], $order[$from]];

        $this->user()->updatePreferences(['reportSectionOrder' => array_map(static fn (ReportSection $item): string => $item->value, $order)]);

        unset($this->sections); // @phpstan-ignore property.notFound
    }

    public function render(): View
    {
        $periodEnum = TransactionPeriod::tryFrom($this->period) ?? TransactionPeriod::ThisMonth;

        return view($this->print ? 'livewire.category-report-print' : 'livewire.category-report', [
            'formatMoney' => MoneyCast::format(...),
            'periodLabel' => $periodEnum->label(),
            'hasPayCycle' => $this->user()->hasPayCycleConfigured(),
            'showCustomRange' => $periodEnum === TransactionPeriod::Custom,
            'topLimit' => self::TOP_LIMIT,
            'canStep' => $this->window() !== null,
            'canStepForward' => $this->canStepForward(),
            'accounts' => Account::query()->where('user_id', $this->user()->id)->orderBy('name')->get(['id', 'name']),
            'tags' => Tag::query()->where('user_id', $this->user()->id)->orderBy('name')->get(['id', 'name']),
            'reportQuery' => $this->reportQuery(),
            'accountName' => $this->print && $this->mode === 'real' && $this->filters()->accountId !== null ? Account::query()->where('user_id', $this->user()->id)->whereKey($this->filters()->accountId)->value('name') : null,
            'tagName' => $this->print && $this->mode === 'real' && $this->filters()->tagId !== null ? Tag::query()->where('user_id', $this->user()->id)->whereKey($this->filters()->tagId)->value('name') : null,
            'filters' => $this->mode === 'real' ? $this->filters() : new ReportFilters,
            'window' => $this->window(),
            'span' => $this->print ? null : $this->span(),
        ]);
    }

    /**
     * The page's state as query parameters, so the print view shows the same report.
     *
     * @return array<string, string|int>
     */
    private function reportQuery(): array
    {
        return array_filter([
            'period' => $this->period,
            'from' => $this->from,
            'to' => $this->to,
            'mode' => $this->mode,
            'account' => $this->account,
            'tag' => $this->tag,
            'min' => $this->min,
            'max' => $this->max,
            'sort' => $this->sort,
            'topOnly' => $this->topOnly ? 1 : null,
            'showSubcategories' => $this->showSubcategories ? 1 : null,
        ], static fn (string|int|null $value): bool => $value !== null && $value !== '');
    }

    /**
     * One direction of the export: root category rows with their monthly amounts. A category
     * sits in the section its whole-range net falls in, as on the page, and a month that ran
     * the other way (a refund after the spend) counts negative there, so each row's months
     * add up to its total.
     *
     * @return array{rows: list<array{name: string, total: int, months: array<string, int>}>, total: int, months: array<string, int>}
     */
    private function csvSection(string $direction): array
    {
        $byRoot = [];
        $sectionMonths = [];
        $netDirection = [];

        foreach ($this->atoms() as $atom) {
            if ($atom['category_id'] !== null) {
                $netDirection[$atom['category_id']] = $atom['direction'];
            }
        }

        foreach ($this->rawAtoms() as $atom) {
            $section = $atom['category_id'] === null ? $atom['direction'] : ($netDirection[$atom['category_id']] ?? null);

            if ($section !== $direction) {
                continue;
            }

            $amount = $atom['direction'] === $direction ? $atom['total'] : -$atom['total'];
            $key = $this->rootOf($atom['category_id']) ?? 'uncat';
            $byRoot[$key][$atom['ym']] = ($byRoot[$key][$atom['ym']] ?? 0) + $amount;
            $sectionMonths[$atom['ym']] = ($sectionMonths[$atom['ym']] ?? 0) + $amount;
        }

        $buckets = $this->buckets($direction);
        $rows = [];

        foreach ($buckets['buckets'] as $bucket) {
            $rows[] = ['name' => $bucket['name'], 'total' => $bucket['total'], 'months' => $byRoot[$bucket['id'] ?? 'uncat'] ?? []];
        }

        return ['rows' => $rows, 'total' => $buckets['sum'], 'months' => $sectionMonths];
    }

    private function rootOf(?int $categoryId): ?int
    {
        if ($categoryId === null || ! isset($this->categoryMap()[$categoryId])) {
            return null;
        }

        $chain = $this->ancestorChain($categoryId);

        return $chain === [] ? null : $chain[array_key_last($chain)];
    }

    private function refreshCharts(): void
    {
        $this->datesCache = null;
        $this->filtersCache = null;
        $this->rawAtomsCache = null;
        $this->monthAtomsCache = null;
        $this->atomsCache = null;
        $this->boundsCache = null;
        $this->monthCountCache = null;
        $this->bucketsCache = [];
        $this->expensePage = 1;
        $this->incomePage = 1;

        $this->dispatch('reports:refresh', chart: $this->chart(), treemap: $this->treemap());
    }

    /**
     * @return array{start: CarbonInterface|null, end: CarbonInterface|null}
     */
    private function dates(): array
    {
        if ($this->datesCache !== null) {
            return $this->datesCache;
        }

        $periodEnum = TransactionPeriod::tryFrom($this->period) ?? TransactionPeriod::ThisMonth;

        return $this->datesCache = $periodEnum->dateRange($this->user(), $this->from, $this->to);
    }

    /**
     * @return list<array{ym: string, direction: string, category_id: int|null, total: int, count: int}>
     */
    private function atoms(): array
    {
        if ($this->atomsCache !== null) {
            return $this->atomsCache;
        }

        return $this->atomsCache = $this->aggregator()->netRefunds($this->rawAtoms(), byMonth: false);
    }

    /**
     * @return list<array{ym: string, direction: string, category_id: int|null, total: int, count: int}>
     */
    private function monthAtoms(): array
    {
        return $this->monthAtomsCache ??= $this->aggregator()->netRefunds($this->rawAtoms(), byMonth: true);
    }

    /**
     * @return list<array{ym: string, direction: string, category_id: int|null, total: int, count: int}>
     */
    private function rawAtoms(): array
    {
        if ($this->rawAtomsCache !== null) {
            return $this->rawAtomsCache;
        }

        $dates = $this->dates();

        return $this->rawAtomsCache = $this->aggregator()->atoms(
            $this->user(),
            $this->mode,
            $dates['start'],
            $dates['end'],
            filters: $this->mode === 'real' ? $this->filters() : null,
        );
    }

    /**
     * Filters from the URL, limited to the user's own account and tag; an unknown or
     * foreign id is ignored.
     */
    private function filters(): ReportFilters
    {
        if ($this->filtersCache !== null) {
            return $this->filtersCache;
        }

        $userId = $this->user()->id;
        $accountId = ctype_digit($this->account) ? (int) $this->account : null;
        $tagId = ctype_digit($this->tag) ? (int) $this->tag : null;

        return $this->filtersCache = new ReportFilters(
            accountId: $accountId !== null && Account::query()->where('user_id', $userId)->whereKey($accountId)->exists() ? $accountId : null,
            tagId: $tagId !== null && Tag::query()->where('user_id', $userId)->whereKey($tagId)->exists() ? $tagId : null,
            minCents: $this->inputToCents($this->min),
            maxCents: $this->inputToCents($this->max),
        );
    }

    private function inputToCents(string $value): ?int
    {
        $value = mb_trim($value);

        if ($value === '' || ! is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return (int) round((float) $value * 100);
    }

    /**
     * The resolved first and last day of the selected period; an open end is today.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    private function window(): ?array
    {
        $dates = $this->dates();

        if ($dates['start'] === null) {
            return null;
        }

        $start = CarbonImmutable::parse($dates['start']->toDateString())->startOfDay();
        $end = $dates['end'] === null
            ? $this->user()->today()->startOfDay()
            : CarbonImmutable::parse($dates['end']->toDateString())->startOfDay();

        return ['start' => $start, 'end' => $end->lessThan($start) ? $start : $end];
    }

    private function canStepForward(): bool
    {
        $window = $this->window();

        return $window !== null && $window['end']->lessThan($this->user()->today()->startOfDay());
    }

    private function coversCurrentMonth(): bool
    {
        $dates = $this->dates();
        $today = $this->user()->today();

        if ($dates['start'] === null || ! CarbonImmutable::parse($dates['start']->toDateString())->isSameDay($today->startOfMonth())) {
            return false;
        }

        return $dates['end'] === null || (
            $dates['end']->toDateString() >= $today->toDateString()
            && $dates['end']->toDateString() <= $today->endOfMonth()->toDateString()
        );
    }

    /**
     * Income and expense totals of the equal-length range just before the selected one.
     *
     * @return array{in: int, out: int}|null
     */
    private function priorTotals(): ?array
    {
        $window = $this->window();

        if ($this->mode !== 'real' || $window === null) {
            return null;
        }

        $prior = $this->aggregator()->priorRange($window['start'], $window['end']);
        $atoms = $this->aggregator()->netRefunds(
            $this->aggregator()->atoms($this->user(), 'real', $prior['start'], $prior['end'], filters: $this->filters()),
            byMonth: false,
        );

        [$in, $out] = $this->directionTotals($atoms);

        return ['in' => $in, 'out' => $out];
    }

    /**
     * @param  list<array{ym: string, direction: string, category_id: int|null, total: int, count: int}>  $atoms
     * @return array{0: int, 1: int} income and expense cents
     */
    private function directionTotals(array $atoms): array
    {
        $in = 0;
        $out = 0;

        foreach ($atoms as $atom) {
            if ($atom['direction'] === TransactionDirection::Credit->value) {
                $in += $atom['total'];
            } else {
                $out += $atom['total'];
            }
        }

        return [$in, $out];
    }

    private function percentChange(int $current, int $prior): ?int
    {
        return $prior === 0 ? null : (int) round(($current - $prior) / abs($prior) * 100);
    }

    /**
     * A custom range that runs backwards is swapped, and the page says so.
     */
    private function settleRange(): void
    {
        $this->from = $this->normaliseDate($this->from);
        $this->to = $this->normaliseDate($this->to);
        $this->rangeSwapped = false;

        if ($this->period === TransactionPeriod::Custom->value && $this->from !== null && $this->to !== null && $this->from > $this->to) {
            [$this->from, $this->to] = [$this->to, $this->from];
            $this->rangeSwapped = true;
        }
    }

    /**
     * @template TRow
     *
     * @param  list<TRow>  $rows
     * @return array{rows: list<TRow>, page: int, pages: int}
     */
    private function paginate(array $rows, int $page): array
    {
        $pages = max(1, (int) ceil(count($rows) / self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);

        return ['rows' => array_slice($rows, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE), 'page' => $page, 'pages' => $pages];
    }

    /**
     * The report's first and last day: the selected dates, or the months the data spans when the
     * period is open-ended.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private function span(): array
    {
        $dates = $this->dates();
        $bounds = $this->bounds();

        return [
            'start' => CarbonImmutable::parse(($dates['start'] ?? $bounds['start'])->toDateString()),
            'end' => CarbonImmutable::parse(($dates['end'] ?? $bounds['end'])->toDateString()),
        ];
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private function bounds(): array
    {
        if ($this->boundsCache !== null) {
            return $this->boundsCache;
        }

        $dates = $this->dates();

        return $this->boundsCache = $this->aggregator()->monthBounds($this->user(), $this->mode, $dates['start'], $dates['end']);
    }

    private function monthCount(): int
    {
        if ($this->monthCountCache !== null) {
            return $this->monthCountCache;
        }

        $bounds = $this->bounds();

        return $this->monthCountCache = max(1, count($this->aggregator()->monthKeys($bounds['start'], $bounds['end'])));
    }

    /**
     * Roll a direction's per-category totals up to their root categories,
     * attaching each root's full-path descendants as inline children (the root's
     * own directly-tagged spend stays in the root total but is not repeated as a
     * child row). A cyclic chain, or a transaction whose category no longer
     * exists, collapses into a single uncategorised bucket.
     *
     * @return array{buckets: list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, pct: float, perMonth: int, bar: int, expandable: bool, children: list<array{id: int, path: string, total: int, count: int, pct: float, perMonth: int, bar: int}>}>, sum: int, max: int}
     */
    private function buckets(string $directionValue): array
    {
        if (isset($this->bucketsCache[$directionValue])) {
            return $this->bucketsCache[$directionValue];
        }

        $map = $this->categoryMap();
        $months = $this->monthCount();

        /** @var array<int|string, array{id: int|null, total: int, count: int}> $totals */
        $totals = [];

        foreach ($this->atoms() as $atom) {
            if ($atom['direction'] !== $directionValue) {
                continue;
            }

            $categoryId = $atom['category_id'];
            $key = $categoryId ?? 'uncat';

            if (! isset($totals[$key])) {
                $totals[$key] = ['id' => $categoryId, 'total' => 0, 'count' => 0];
            }

            $totals[$key]['total'] += $atom['total'];
            $totals[$key]['count'] += $atom['count'];
        }

        /** @var array<int|string, array{id: int|null, total: int, count: int, members: list<array{id: int, total: int, count: int}>}> $roots */
        $roots = [];

        foreach ($totals as $entry) {
            $categoryId = $entry['id'];
            $rootId = $this->rootOf($categoryId);
            $memberId = $rootId !== null ? $categoryId : null;

            $key = $rootId ?? 'uncat';

            if (! isset($roots[$key])) {
                $roots[$key] = ['id' => $rootId, 'total' => 0, 'count' => 0, 'members' => []];
            }

            $roots[$key]['total'] += $entry['total'];
            $roots[$key]['count'] += $entry['count'];

            if ($memberId !== null) {
                $roots[$key]['members'][] = ['id' => $memberId, 'total' => $entry['total'], 'count' => $entry['count']];
            }
        }

        $sum = 0;
        $max = 0;

        foreach ($roots as $root) {
            $sum += $root['total'];
            $max = max($max, $root['total']);
        }

        uasort($roots, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $buckets = [];
        $index = 0;

        foreach ($roots as $root) {
            $rootId = $root['id'];
            $name = $rootId !== null ? ($map[$rootId]['name'] ?? 'Uncategorised') : 'Uncategorised';
            $color = $this->paletteColor(
                $rootId !== null && isset($map[$rootId]) ? $map[$rootId]['color'] : null,
                $index,
            );

            $children = [];
            $expandable = false;

            foreach ($root['members'] as $member) {
                if ($member['id'] === $rootId) {
                    continue;
                }

                $expandable = true;

                $children[] = [
                    'id' => $member['id'],
                    'path' => $this->pathLabel($member['id'], $map),
                    'total' => $member['total'],
                    'count' => $member['count'],
                    'pct' => $sum > 0 ? round($member['total'] / $sum * 100, 1) : 0.0,
                    'perMonth' => intdiv($member['total'], $months),
                    'bar' => $root['total'] > 0 ? (int) min(100, max(0, round($member['total'] / $root['total'] * 100))) : 0,
                ];
            }

            usort($children, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

            $buckets[] = [
                'id' => $rootId,
                'name' => $name,
                'icon' => $rootId !== null ? $this->resolveIconFromMap($rootId, $map) : null,
                'color' => $color,
                'total' => $root['total'],
                'count' => $root['count'],
                'pct' => $sum > 0 ? round($root['total'] / $sum * 100, 1) : 0.0,
                'perMonth' => intdiv($root['total'], $months),
                'bar' => $max > 0 ? (int) min(100, max(0, round($root['total'] / $max * 100))) : 0,
                'expandable' => $expandable,
                'children' => $children,
            ];

            $index++;
        }

        return $this->bucketsCache[$directionValue] = ['buckets' => $buckets, 'sum' => $sum, 'max' => $max];
    }

    /**
     * @param  list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, pct: float, perMonth: int, bar: int, expandable: bool, children: list<array{id: int, path: string, total: int, count: int, pct: float, perMonth: int, bar: int}>}>  $buckets
     * @return list<array{x: string, y: int, color: string}>
     */
    private function treemapNodes(array $buckets): array
    {
        $nodes = [];

        foreach ($buckets as $bucket) {
            if ($bucket['total'] <= 0) {
                continue;
            }

            $nodes[] = ['x' => $bucket['name'], 'y' => $bucket['total'], 'color' => $bucket['color']];
        }

        return $nodes;
    }

    /**
     * @param  list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, pct: float, perMonth: int, bar: int, expandable: bool, children: list<array{id: int, path: string, total: int, count: int, pct: float, perMonth: int, bar: int}>}>  $buckets
     * @return list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, pct: float, perMonth: int, bar: int, expandable: bool, children: list<array{id: int, path: string, total: int, count: int, pct: float, perMonth: int, bar: int}>}>
     */
    private function limitBuckets(array $buckets): array
    {
        if ($this->sort === 'count') {
            usort($buckets, static fn (array $a, array $b): int => [$b['count'], $b['total']] <=> [$a['count'], $a['total']]);
        }

        return $this->topOnly ? array_slice($buckets, 0, self::TOP_LIMIT) : $buckets;
    }

    /**
     * @param  array<int, array{id: int, name: string, parent_id: int|null, icon: string|null, color: string|null}>  $map
     */
    private function pathLabel(int $categoryId, array $map): string
    {
        $chain = $this->ancestorChain($categoryId);

        if ($chain === []) {
            return $map[$categoryId]['name'] ?? 'Uncategorised';
        }

        $names = [];

        foreach (array_reverse($chain) as $id) {
            $names[] = $map[$id]['name'];
        }

        return implode(' / ', $names);
    }

    private function normaliseDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @return list<int>
     */
    private function ancestorChain(int $categoryId): array
    {
        $map = $this->categoryMap();
        $chain = [];
        $visited = [];
        $currentId = $categoryId;

        while ($currentId !== null && isset($map[$currentId])) {
            if (isset($visited[$currentId])) {
                return [];
            }

            $visited[$currentId] = true;
            $chain[] = $currentId;
            $currentId = $map[$currentId]['parent_id'];
        }

        return $chain;
    }

    /**
     * @param  array<int, array{id: int, name: string, parent_id: int|null, icon: string|null, color: string|null}>  $map
     */
    private function resolveIconFromMap(?int $categoryId, array $map): ?string
    {
        $visited = [];

        while ($categoryId !== null && isset($map[$categoryId]) && ! isset($visited[$categoryId])) {
            $visited[$categoryId] = true;

            if ($map[$categoryId]['icon'] !== null) {
                return $map[$categoryId]['icon'];
            }

            $categoryId = $map[$categoryId]['parent_id'];
        }

        return null;
    }

    private function paletteColor(?string $color, int $index): string
    {
        if ($color !== null && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) === 1) {
            return $color;
        }

        return self::FALLBACK_COLORS[$index % count(self::FALLBACK_COLORS)];
    }

    /**
     * @return array<int, array{id: int, name: string, parent_id: int|null, icon: string|null, color: string|null}>
     */
    private function categoryMap(): array
    {
        if ($this->categoryMap !== null) {
            return $this->categoryMap;
        }

        $map = [];

        foreach (Category::query()->where('user_id', $this->user()->id)->get(['id', 'name', 'parent_id', 'icon', 'color']) as $category) {
            $map[$category->id] = [
                'id' => $category->id,
                'name' => $category->name,
                'parent_id' => $category->parent_id,
                'icon' => $category->icon,
                'color' => $category->color,
            ];
        }

        return $this->categoryMap = $map;
    }

    private function aggregator(): ReportAggregator
    {
        return app(ReportAggregator::class);
    }

    private function user(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new RuntimeException('The reports page requires an authenticated user.');
        }

        return $user;
    }
}
