<?php

declare(strict_types=1);

namespace App\Services\InsightGenerators;

use App\Contracts\InsightGenerator;
use App\Enums\AccountClass;
use App\Enums\InsightPriority;
use App\Enums\InsightType;
use App\Models\Account;
use App\Models\User;
use App\Services\InsightRecorder;
use App\Support\Insights\InsightMoney;

final readonly class IdleCashGenerator implements InsightGenerator
{
    private const int MIN_BALANCE_CENTS = 500000;

    private const int IDLE_DAYS = 60;

    private const int LIMIT = 2;

    public function __construct(private InsightRecorder $recorder) {}

    public function key(): string
    {
        return 'idle-cash';
    }

    public function types(): array
    {
        return [InsightType::IdleCash];
    }

    public function generate(User $user): array
    {
        $since = $user->today()->subDays(self::IDLE_DAYS);

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->tracked()
            ->active()
            ->whereIn('type', [AccountClass::Transaction, AccountClass::Savings])
            ->where('currency', $user->base_currency)
            ->where('balance', '>=', self::MIN_BALANCE_CENTS)
            ->where('created_at', '<=', $since->startOfDay())
            ->whereDoesntHave('transactions', fn ($query) => $query->whereDoesntHave('children', fn ($children) => $children->whereNull('deleted_at'))->where('post_date', '>=', $since->toDateString()))
            ->whereDoesntHave('valuations', fn ($query) => $query->where('valued_on', '>=', $since->toDateString())->where('difference', '!=', 0))
            ->orderByDesc('balance')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        $keys = [];

        foreach ($accounts as $account) {
            $key = "idle_cash:{$account->id}";

            $this->recorder->record(
                user: $user,
                type: InsightType::IdleCash,
                priority: InsightPriority::Low,
                dedupKey: $key,
                title: "{$account->name} has cash sitting idle",
                body: sprintf(
                    '%s has held %s with no activity for %d days. Consider moving it somewhere it works harder.',
                    $account->name,
                    InsightMoney::format($account->balance, $account->currency),
                    self::IDLE_DAYS,
                ),
                figures: [
                    'account_id' => $account->id,
                    'balance_cents' => $account->balance,
                    'currency' => $account->currency,
                    'idle_days' => self::IDLE_DAYS,
                ],
                comparison: [
                    'account_id' => $account->id,
                    'balance_band' => intdiv($account->balance, 100000),
                ],
                account: $account,
                actionRoute: 'accounts.show',
            );

            $keys[] = $key;
        }

        return $keys;
    }
}
