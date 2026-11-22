<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\TradeActivity;
use App\Models\Trade;
use App\Models\User;
use App\Services\CurrencyConverter;
use App\Services\Investments\GainsCalculator;
use App\Services\Investments\InvestmentPortfolio;
use Carbon\CarbonImmutable;

/**
 * The reports page's investment sections and the gate for the dashboard's investment widget. Everything
 * is built from InvestmentPortfolio and GainsCalculator over the user's own active investment accounts,
 * and only while budget.investments.enabled is on. A realised gain that cannot be known (a sell with no
 * earlier purchase, a foreign sell with no exchange rate for its day) is counted as withheld, never
 * computed at a guess. Only a Sell is a sale: a sweep out, a transfer or a withdrawal is not.
 *
 * @phpstan-import-type Row from InvestmentPortfolio
 */
final readonly class InvestmentReport
{
    public function __construct(
        private InvestmentPortfolio $portfolio,
        private GainsCalculator $gains,
    ) {}

    /** Whether the user has anything to see: the flag is on and an active investment account holds a position. */
    public function hasData(User $user): bool
    {
        return config('budget.investments.enabled') === true && $this->portfolio->hasHoldings($user, $this->portfolio->accounts($user));
    }

    /** Whether the investment performance section applies: the flag is on and the user has an active investment account. */
    public function appliesTo(User $user): bool
    {
        return config('budget.investments.enabled') === true && $this->portfolio->accounts($user) !== [];
    }

    /**
     * What was paid into and out of the investment accounts between two days.
     *
     * @return array{contributions: int, withdrawals: int, net: int}
     */
    public function flows(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $converter = CurrencyConverter::enabled() ? app(CurrencyConverter::class) : null;
        $in = 0;
        $out = 0;

        $accounts = $this->portfolio->accounts($user);
        $ids = array_map(static fn ($account): int => $account->id, $accounts);

        foreach ($accounts as $account) {
            $flows = $this->portfolio->flows($user, $account, $from, $to, $converter, $ids);
            $in += $flows['inflows'];
            $out += $flows['outflows'];
        }

        return ['contributions' => $in, 'withdrawals' => $out, 'net' => $in - $out];
    }

    /** Whether anything was paid into or out of the investment accounts between two days. */
    public function hasFlows(User $user, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        if (! $this->appliesTo($user)) {
            return false;
        }

        $flows = $this->flows($user, $from, $to);

        return $flows['contributions'] !== 0 || $flows['withdrawals'] !== 0;
    }

    /**
     * The performance section for a period: value now, return since the user's cost (unrealised gain over
     * the cost of the positions whose cost is known) and over the period, what was contributed and
     * withdrawn, the five largest holdings, and the gains: unrealised now and realised on the sells in
     * the period, with the sells behind the realised figure.
     *
     * @return array{portfolioValue: int, holdingsValue: int, totalReturnBp: int|null, periodReturnBp: int|null, contributions: int, withdrawals: int, top: list<Row>, unrealised: int|null, realised: int, withheld: int, sales: list<array{trade: Trade, gain: int|null}>}
     */
    public function performance(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $accounts = $this->portfolio->accounts($user);
        $overview = $this->portfolio->overview($user, $accounts, $from, $to);
        $flows = $this->flows($user, $from, $to);

        $sales = [];
        $realised = 0;
        $withheld = 0;

        $trades = Trade::query()
            ->forUser($user)
            ->whereIn('account_id', array_map(static fn ($account): int => $account->id, $accounts))
            ->where('activity', TradeActivity::Sell)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->with('security')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        foreach ($trades as $trade) {
            $gain = $this->gains->realised($user, $trade);
            $gain === null ? $withheld++ : $realised += $gain;
            $sales[] = ['trade' => $trade, 'gain' => $gain];
        }

        return [
            'portfolioValue' => $overview['portfolioValue'],
            'holdingsValue' => $overview['holdingsValue'],
            'totalReturnBp' => $overview['totalReturnBp'],
            'periodReturnBp' => $overview['periodReturnBp'],
            'contributions' => $flows['contributions'],
            'withdrawals' => $flows['withdrawals'],
            'top' => $overview['top'],
            'unrealised' => $overview['unrealised'],
            'realised' => $realised,
            'withheld' => $withheld,
            'sales' => $sales,
        ];
    }
}
