<?php

declare(strict_types=1);

namespace App\Enums;

enum InsightType: string
{
    case SpendingAnomaly = 'spending_anomaly';
    case CashFlowWarning = 'cash_flow_warning';
    case NetWorthMilestone = 'net_worth_milestone';
    case SubscriptionAudit = 'subscription_audit';
    case SavingsRateChange = 'savings_rate_change';
    case IdleCash = 'idle_cash';
    case BudgetAtRisk = 'budget_at_risk';
    case BudgetOnTrack = 'budget_on_track';
    case GoalDepleted = 'goal_depleted';
}
