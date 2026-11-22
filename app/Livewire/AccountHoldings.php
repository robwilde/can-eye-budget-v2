<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\RecordTrade;
use App\DTOs\SecuritySearchResult;
use App\Enums\AccountClass;
use App\Enums\TradeActivity;
use App\Exceptions\HoldingRefusedException;
use App\Exceptions\TradeRefusedException;
use App\Models\Account;
use App\Models\Holding;
use App\Models\Security;
use App\Models\SecurityPrice;
use App\Models\Trade;
use App\Models\User;
use App\Services\Investments\CostBasisResolver;
use App\Services\Investments\HoldingDeleter;
use App\Services\Investments\HoldingRemapper;
use App\Services\Investments\InvestmentPortfolio;
use App\Services\Investments\SecurityPriceSyncer;
use App\Services\Investments\SecurityRegistrar;
use App\Services\Investments\SecuritySearchService;
use App\Services\Investments\TradeRecorder;
use App\Support\Investments\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * The Holdings tab of an investment account, shown only while budget.investments.enabled is on. It is
 * a thin layer over the investment services: every write goes through TradeRecorder,
 * CostBasisResolver, HoldingRemapper, HoldingDeleter or SecurityPriceSyncer, and every figure comes
 * from InvestmentPortfolio, so no rule lives here. Every public action is audited by the Livewire
 * hook, and a refusal is added to the error bag so it is audited as a failure. A holding id sent by the
 * browser is re-scoped to this account of this user before it is used.
 *
 * @property-read array<string, mixed> $statement
 */
final class AccountHoldings extends Component
{
    #[Locked]
    public Account $account;

    public ?int $detail = null;

    public string $activity = 'buy';

    public string $securityRef = '';

    public string $tradeDate = '';

    public string $quantity = '';

    public string $price = '';

    public string $fee = '';

    public string $amount = '';

    public string $counterAccountId = '';

    public string $tickerQuery = '';

    public string $costBasis = '';

    public string $notice = '';

    public function mount(Account $account): void
    {
        abort_unless(config('budget.investments.enabled') === true, 404);
        abort_unless($account->user_id === auth()->id() && $account->type === AccountClass::Investment, 404);

        $this->account = $account;
        $this->tradeDate = $this->user()->today()->toDateString();
    }

    public function recordTrade(): void
    {
        $this->notice = '';
        $this->resetErrorBag();

        $this->validate([
            'activity' => ['required', 'in:'.implode(',', array_map(fn (TradeActivity $activity): string => $activity->value, TradeActivity::cases()))],
            'tradeDate' => ['required', 'date', 'before_or_equal:'.$this->user()->today()->toDateString()],
        ]);

        $activity = TradeActivity::from($this->activity);

        try {
            $input = new RecordTrade(
                accountId: $this->account->id,
                activity: $activity,
                date: $this->tradeDate,
                securityId: $activity->needsSecurity() ? $this->resolveSecurity()->id : null,
                quantity: $activity->movesShares() ? mb_trim($this->quantity) : null,
                price: $activity->movesShares() ? $this->cents($this->price, 'price') : null,
                fee: $activity->movesShares() && mb_trim($this->fee) !== '' ? $this->cents($this->fee, 'fee') : 0,
                amount: $activity->movesShares() ? null : $this->cents($this->amount, 'amount'),
                counterAccountId: $this->counterAccountId === '' ? null : (int) $this->counterAccountId,
            );

            app(TradeRecorder::class)->record($this->user(), $input);
        } catch (TradeRefusedException|HoldingRefusedException $e) {
            $this->refuse($e->getMessage());

            return;
        }

        $this->notice = __('Recorded.');
        $this->reset('quantity', 'price', 'fee', 'amount', 'counterAccountId', 'securityRef', 'tickerQuery');
        $this->unsetComputed();
    }

    public function setCostBasis(int $holdingId, string $total): void
    {
        $this->guarded(function () use ($holdingId, $total): void {
            $cents = $this->cents($total, 'cost basis');

            app(CostBasisResolver::class)->setManual($this->user(), $this->holding($holdingId), $cents);
        });
    }

    public function unlockCostBasis(int $holdingId): void
    {
        $this->guarded(fn () => app(CostBasisResolver::class)->unlock($this->user(), $this->holding($holdingId)));
    }

    public function remap(int $holdingId, string $securityRef): void
    {
        $this->guarded(function () use ($holdingId, $securityRef): void {
            $holding = $this->holding($holdingId);

            app(HoldingRemapper::class)->remap($this->user(), $holding, $this->securityFor($securityRef));

            $this->detail = null;
            $this->tickerQuery = '';
        });
    }

    public function resetMapping(int $holdingId): void
    {
        $this->guarded(function () use ($holdingId): void {
            app(HoldingRemapper::class)->reset($this->user(), $this->holding($holdingId));

            $this->detail = null;
        });
    }

    public function deleteHolding(int $holdingId): void
    {
        $this->guarded(function () use ($holdingId): void {
            app(HoldingDeleter::class)->delete($this->user(), $this->holding($holdingId));

            $this->detail = null;
        }, 'This holding cannot be deleted: ');
    }

    /**
     * Fetches the last 31 days of prices for the securities held on this account. An offline security
     * is not fetched and is named in an alert; a security that came back with no prices shows its error
     * on its row.
     */
    public function refreshPrices(): void
    {
        $this->guarded(function (): void {
            $offline = Security::query()
                ->whereIn('id', Holding::query()->forUser($this->user())->where('account_id', $this->account->id)->select('security_id'))
                ->whereNotNull('offline_at')
                ->get();

            $result = app(SecurityPriceSyncer::class)->sync($this->user(), force: true, accountId: $this->account->id);

            if ($offline->isNotEmpty()) {
                throw new HoldingRefusedException(__('Prices were not refreshed for :names: the security is offline.', ['names' => $offline->pluck('ticker')->implode(', ')]));
            }

            if ($result['failed'] > 0) {
                throw new HoldingRefusedException(__('Some prices could not be refreshed; the error is shown on the holding.'));
            }

            if ($result['synced'] === 0) {
                throw new HoldingRefusedException(__('No prices were refreshed. Check that a price provider is enabled.'));
            }

            $this->notice = __('Prices refreshed.');
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function statement(): array
    {
        return app(InvestmentPortfolio::class)->statement($this->user(), $this->account);
    }

    /**
     * The holding whose detail is open, when it is one of this account's.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function detailRow(): ?array
    {
        foreach ($this->statement['rows'] as $row) { // @phpstan-ignore property.notFound
            if ($row['holding']->id === $this->detail) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, Trade>
     */
    #[Computed]
    public function detailTrades(): Collection
    {
        $row = $this->detailRow; // @phpstan-ignore property.notFound

        if ($row === null) {
            return new Collection;
        }

        return Trade::query()->forUser($this->user())->where('account_id', $this->account->id)->where('security_id', $row['holding']->security_id)->orderByDesc('date')->orderByDesc('id')->get();
    }

    #[Computed]
    public function lastPriceDate(): ?string
    {
        $row = $this->detailRow; // @phpstan-ignore property.notFound

        return $row === null ? null : SecurityPrice::query()->where('security_id', $row['holding']->security_id)->max('date');
    }

    /**
     * @return list<SecuritySearchResult>
     */
    #[Computed]
    public function searchResults(): array
    {
        return mb_strlen(mb_trim($this->tickerQuery)) >= 2 ? app(SecuritySearchService::class)->search($this->tickerQuery) : [];
    }

    /**
     * Securities this account already holds or has traded, and securities already known to the app whose
     * ticker contains the search text, for the trade form.
     *
     * @return Collection<int, Security>
     */
    #[Computed]
    public function knownSecurities(): Collection
    {
        return Security::query()
            ->whereIn('id', Trade::query()->forUser($this->user())->where('account_id', $this->account->id)->whereNotNull('security_id')->select('security_id'))
            ->orWhereIn('id', Holding::query()->forUser($this->user())->where('account_id', $this->account->id)->select('security_id'))
            ->when(mb_strlen(mb_trim($this->tickerQuery)) >= 2, fn ($query) => $query->orWhere('ticker', 'like', '%'.addcslashes(Security::normalise(mb_trim($this->tickerQuery)), '%_\\').'%'))
            ->orderBy('ticker')
            ->get();
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function counterAccounts(): Collection
    {
        return Account::query()->where('user_id', $this->user()->id)->whereKeyNot($this->account->id)->orderBy('name')->get(['id', 'name']);
    }

    public function render(): View
    {
        return view('livewire.account-holdings', ['activities' => TradeActivity::cases()]);
    }

    private function user(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new RuntimeException('The holdings tab requires an authenticated user.');
        }

        return $user;
    }

    /**
     * Runs a write, reports a refusal as an alert that is also audited as a failure, and clears the
     * cached figures on success.
     */
    private function guarded(callable $write, string $prefix = ''): void
    {
        $this->notice = '';
        $this->resetErrorBag();

        try {
            $write();
        } catch (TradeRefusedException|HoldingRefusedException $e) {
            $this->refuse($prefix.$e->getMessage());

            return;
        }

        $this->unsetComputed();
    }

    private function refuse(string $message): void
    {
        $this->addError('holdings', $message);
    }

    private function unsetComputed(): void
    {
        unset($this->statement, $this->detailRow, $this->detailTrades, $this->lastPriceDate, $this->knownSecurities, $this->searchResults); // @phpstan-ignore property.notFound
    }

    /**
     * The holding behind an id from the browser, limited to this account of this user.
     *
     * @throws HoldingRefusedException when it is not one of this account's holdings
     */
    private function holding(int $holdingId): Holding
    {
        return Holding::query()->forUser($this->user())->where('account_id', $this->account->id)->find($holdingId)
            ?? throw new HoldingRefusedException(__('That holding is not on this account.'));
    }

    /**
     * @throws TradeRefusedException when the text is not an amount
     */
    private function cents(string $dollars, string $what): int
    {
        return Decimal::dollarsToCents($dollars) ?? throw new TradeRefusedException(__('The :what is not an amount.', ['what' => $what]));
    }

    /**
     * @throws TradeRefusedException when no security was chosen
     */
    private function resolveSecurity(): Security
    {
        try {
            return $this->securityFor($this->securityRef);
        } catch (HoldingRefusedException $e) {
            throw new TradeRefusedException($e->getMessage(), 0, $e);
        }
    }

    /**
     * An existing security ("id:12") or one the ticker search just found ("new:VAS|XASX"), created when
     * it is new. A search result is re-checked against the provider's answer, not trusted from the browser.
     *
     * @throws HoldingRefusedException when the reference names nothing
     */
    private function securityFor(string $ref): Security
    {
        if (str_starts_with($ref, 'id:')) {
            return Security::query()->find((int) mb_substr($ref, 3)) ?? throw new HoldingRefusedException(__('Choose a security.'));
        }

        if (str_starts_with($ref, 'new:')) {
            $key = mb_substr($ref, 4);

            foreach ($this->searchResults as $result) { // @phpstan-ignore property.notFound
                if ($result->key() === $key) {
                    return app(SecurityRegistrar::class)->firstOrCreate($result);
                }
            }
        }

        throw new HoldingRefusedException(__('Choose a security.'));
    }
}
