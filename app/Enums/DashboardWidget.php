<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Account;
use App\Models\Insight;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Recurring\RecurringGate;
use App\Services\Reports\InvestmentReport;
use App\Services\Transfers\TransferReviewQueue;

/**
 * The dashboard's widget registry. Case order is the default layout order.
 *
 * A widget is either a Livewire child (component() names it, lazy() says whether it loads
 * after the page) or an inline section (view() names its Blade partial). Adding a widget
 * means adding a case here and one of the two; the Dashboard renders it through the layout,
 * and a widget that depends on the selected period reads App\Support\Dashboard\DashboardPeriod.
 */
enum DashboardWidget: string
{
    case Insights = 'insights';
    case StatementsDue = 'statements-due';
    case PendingTransfers = 'pending-transfers';
    case PaySummary = 'pay-summary';
    case NetWorthChart = 'net-worth-chart';
    case BalanceSheet = 'balance-sheet';
    case InvestmentSummary = 'investment-summary';
    case MoneyFlow = 'money-flow';
    case SpendingTrend = 'spending-trend';
    case CashFlow = 'cash-flow';
    case Outflows = 'outflows';
    case PayCycleCalendar = 'pay-cycle-calendar';
    case BudgetsThisCycle = 'budgets-this-cycle';
    case UpcomingPlans = 'upcoming-plans';
    case PaycheckPlan = 'paycheck-plan';
    case RecentSpending = 'recent-spending';
    case MonthlyProjection = 'monthly-projection';

    public function label(): string
    {
        return match ($this) {
            self::Insights => 'Insights',
            self::StatementsDue => 'Statements due',
            self::PendingTransfers => 'Transfers to review',
            self::PaySummary => 'Pay summary',
            self::NetWorthChart => 'Net worth',
            self::BalanceSheet => 'Balance sheet',
            self::InvestmentSummary => 'Investments',
            self::MoneyFlow => 'Money flow',
            self::SpendingTrend => 'Spending trend',
            self::CashFlow => 'Cash flow',
            self::Outflows => 'Outflows',
            self::PayCycleCalendar => 'Pay cycle calendar',
            self::BudgetsThisCycle => 'Budgets this cycle',
            self::UpcomingPlans => 'Upcoming plans',
            self::PaycheckPlan => 'Paycheck plan',
            self::RecentSpending => 'Recent spending',
            self::MonthlyProjection => 'Monthly projection',
        };
    }

    /** The Livewire component name of a widget that is a child component, or null for an inline section. */
    public function component(): ?string
    {
        return match ($this) {
            self::NetWorthChart => 'dashboard.net-worth-chart',
            self::BalanceSheet => 'dashboard.balance-sheet',
            self::InvestmentSummary => 'dashboard.investment-summary',
            self::MoneyFlow => 'dashboard.money-flow',
            self::SpendingTrend => 'dashboard.spending-trend',
            self::CashFlow => 'dashboard.cash-flow',
            self::Outflows => 'dashboard.outflows',
            self::PayCycleCalendar => 'dashboard.pay-cycle-calendar',
            self::MonthlyProjection => 'dashboard.monthly-projection',
            self::PaycheckPlan => 'dashboard.paycheck-plan',
            default => null,
        };
    }

    /** The Blade partial of an inline section, or null for a child component. */
    public function view(): ?string
    {
        return $this->component() === null ? 'livewire.dashboard.widgets.'.$this->value : null;
    }

    public function lazy(): bool
    {
        return in_array($this, [self::NetWorthChart, self::BalanceSheet, self::InvestmentSummary, self::MoneyFlow, self::SpendingTrend, self::CashFlow, self::Outflows, self::MonthlyProjection, self::PaycheckPlan], true);
    }

    /**
     * Whether the widget is a child component that reads the dashboard period. Such a widget has a
     * `#[Reactive] public string $periodStamp = ''` property and reads App\Support\Dashboard\DashboardPeriod
     * in its computed values; the dashboard passes it the period stamp, so the widget re-renders when
     * the user picks another period.
     */
    public function followsPeriod(): bool
    {
        return in_array($this, [self::NetWorthChart, self::CashFlow, self::Outflows, self::InvestmentSummary], true);
    }

    /**
     * The parameters the dashboard mounts a child component with: lazy loading, and for a widget
     * that follows the period the period stamp (DashboardPeriod::stamp()).
     *
     * @return array<string, bool|string>
     */
    public function componentParams(string $periodStamp): array
    {
        return array_filter([
            'lazy' => $this->lazy() ?: null,
            'periodStamp' => $this->followsPeriod() ? $periodStamp : null,
        ], static fn (bool|string|null $value): bool => $value !== null);
    }

    /** Chart widgets can be given a compact, auto or tall height. */
    public function isChart(): bool
    {
        return in_array($this, [self::NetWorthChart, self::MoneyFlow, self::SpendingTrend, self::CashFlow, self::Outflows, self::MonthlyProjection], true);
    }

    public function defaultWidth(): WidgetWidth
    {
        return match ($this) {
            self::BudgetsThisCycle, self::UpcomingPlans, self::RecentSpending, self::NetWorthChart, self::BalanceSheet, self::MoneyFlow, self::SpendingTrend, self::CashFlow, self::Outflows => WidgetWidth::Half,
            default => WidgetWidth::Full,
        };
    }

    /**
     * Whether the dashboard may show the widget at all. A widget behind a flag is neither shown nor
     * offered while it has nothing to draw: the investment summary needs the investments flag and a
     * position on an active investment account.
     */
    public function availableTo(User $user): bool
    {
        return match ($this) {
            self::InvestmentSummary => app(InvestmentReport::class)->hasData($user),
            self::PaycheckPlan => RecurringGate::enabled($user),
            default => true,
        };
    }

    /**
     * Whether the user has anything for this widget. The dashboard only offers a hidden widget
     * that has data; a widget that is shown draws its own empty state.
     */
    public function hasData(User $user): bool
    {
        return match ($this) {
            self::Insights => config('budget.insights_enabled') === true
                && Insight::query()->where('user_id', $user->id)->exists(),
            self::StatementsDue => Account::query()->where('user_id', $user->id)->tracked()->statementDueForLastMonth()->exists(),
            self::PendingTransfers => app(TransferReviewQueue::class)->pendingCount($user) > 0,
            self::NetWorthChart, self::BalanceSheet => Account::query()->where('user_id', $user->id)->exists(),
            self::PayCycleCalendar => $user->hasPayCycleConfigured(),
            self::BudgetsThisCycle => $user->budgets()->exists(),
            self::UpcomingPlans => $user->plannedTransactions()->visible()->exists(),
            self::RecentSpending, self::MoneyFlow, self::SpendingTrend, self::CashFlow, self::Outflows => Transaction::query()->where('user_id', $user->id)->exists(),
            self::MonthlyProjection => $user->primary_account_id !== null,
            self::InvestmentSummary => app(InvestmentReport::class)->hasData($user),
            self::PaySummary => true,
            self::PaycheckPlan => RecurringGate::enabled($user),
        };
    }
}
