@php
    use App\Casts\MoneyCast;
    use App\Enums\TradeActivity;
    use App\Support\Investments\Decimal;

    $statement = $this->statement;
    $detailRow = $this->detailRow;
    $pct = static fn (?int $bp): string => $bp === null ? '--' : number_format($bp / 100, 2).'%';
    $money = static fn (?int $cents): string => $cents === null ? '--' : MoneyCast::format($cents);
@endphp
<div class="space-y-6" data-test="account-holdings">
    @error('holdings')
        <flux:callout variant="danger" icon="exclamation-triangle" data-test="holdings-alert">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @enderror

    @if ($notice !== '')
        <flux:callout variant="success" icon="check-circle" data-test="holdings-notice">
            <flux:callout.text>{{ $notice }}</flux:callout.text>
        </flux:callout>
    @endif

    <x-cib.card data-test="investment-statement">
        <div class="mb-3 flex items-center justify-between">
            <div class="cib-label">{{ __('Statement') }}</div>
            <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="refreshPrices" data-test="refresh-prices">{{ __('Refresh prices') }}</flux:button>
        </div>
        <dl class="grid gap-4 sm:grid-cols-3">
            <div><dt class="cib-label">{{ __('Portfolio value') }}</dt><dd data-test="statement-portfolio">{{ $money($statement['portfolioValue']) }}</dd></div>
            <div><dt class="cib-label">{{ __('Cash') }}</dt><dd data-test="statement-cash">{{ $money($statement['cash']) }}</dd></div>
            <div><dt class="cib-label">{{ __('Holdings value') }}</dt><dd data-test="statement-holdings">{{ $money($statement['holdingsValue']) }}</dd></div>
            <div><dt class="cib-label">{{ __('Day change') }}</dt><dd data-test="statement-day-change">{{ $money($statement['dayChange']) }}</dd></div>
            <div><dt class="cib-label">{{ __('Unrealised gains') }}</dt><dd data-test="statement-unrealised">{{ $money($statement['unrealised']) }}</dd></div>
            <div><dt class="cib-label">{{ __('30-day return') }}</dt><dd data-test="statement-period-return">{{ $pct($statement['periodReturnBp']) }}</dd></div>
            <div><dt class="cib-label">{{ __('All-time contributions') }}</dt><dd data-test="statement-contributions">{{ $money($statement['contributions']) }}</dd></div>
            <div><dt class="cib-label">{{ __('Dividends') }}</dt><dd data-test="statement-dividends">{{ $money($statement['dividends']) }}</dd></div>
            <div><dt class="cib-label">{{ __('Interest') }}</dt><dd data-test="statement-interest">{{ $money($statement['interest']) }}</dd></div>
        </dl>
        @if ($statement['top'] !== [])
            <div class="mt-4">
                <div class="cib-label">{{ __('Top holdings') }}</div>
                <ul class="mt-1 space-y-1" data-test="statement-top">
                    @foreach ($statement['top'] as $row)
                        <li class="flex justify-between"><span>{{ $row['security']->ticker }}</span><span>{{ $pct($row['weightBp']) }}</span></li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-cib.card>

    <x-cib.card data-test="holdings-table">
        @if ($statement['rows'] === [])
            <flux:text data-test="holdings-empty">{{ __('No holdings yet. Record a trade to start.') }}</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Security') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Quantity') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Avg cost') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Price') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Value') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Weight') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Return') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($statement['rows'] as $row)
                        <flux:table.row wire:key="holding-{{ $row['holding']->id }}" data-test="holding-row">
                            <flux:table.cell>
                                {{ $row['security']->ticker }}
                                @if ($row['security']->last_error !== null)
                                    <span class="text-red-600" data-test="holding-error">{{ __('Price error: :error', ['error' => $row['security']->last_error]) }}</span>
                                @endif
                                @if ($row['security']->offline_at !== null)
                                    <span class="text-amber-600">{{ __('Offline') }}</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ Decimal::trim($row['quantity']) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $money($row['costPerShare']) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $money($row['price']) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $money($row['value']) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $pct($row['weightBp']) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $pct($row['returnBp']) }}</flux:table.cell>
                            <flux:table.cell align="end"><flux:button size="xs" variant="ghost" wire:click="$set('detail', {{ $row['holding']->id }})" data-test="holding-detail-open">{{ __('Detail') }}</flux:button></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </x-cib.card>

    @if ($detailRow !== null)
        @php $holding = $detailRow['holding']; @endphp
        <x-cib.card data-test="holding-detail">
            <div class="mb-3 flex items-center justify-between">
                <flux:heading>{{ $detailRow['security']->ticker }} · {{ $detailRow['security']->name }}</flux:heading>
                <flux:button size="xs" variant="ghost" wire:click="$set('detail', null)">{{ __('Close') }}</flux:button>
            </div>
            <dl class="grid gap-4 sm:grid-cols-4">
                <div><dt class="cib-label">{{ __('Market price') }}</dt><dd data-test="detail-price">{{ $money($detailRow['price']) }}</dd></div>
                <div><dt class="cib-label">{{ __('Book value') }}</dt><dd data-test="detail-book">{{ $detailRow['costPerShare'] === null ? '--' : MoneyCast::format(Decimal::mulCents($detailRow['quantity'], $detailRow['costPerShare'])) }}</dd></div>
                <div><dt class="cib-label">{{ __('Market value') }}</dt><dd data-test="detail-market">{{ $money($detailRow['value']) }}</dd></div>
                <div><dt class="cib-label">{{ __('Last price update') }}</dt><dd data-test="detail-updated">{{ $this->lastPriceDate ?? '--' }}</dd></div>
            </dl>

            <div class="mt-4 flex flex-wrap items-end gap-2">
                <flux:input wire:model="costBasis" label="{{ __('Total cost basis') }}" placeholder="0.00" class="max-w-40" data-test="cost-basis-input"/>
                <flux:button size="sm" wire:click="setCostBasis({{ $holding->id }}, $wire.costBasis)" data-test="set-cost-basis">{{ __('Set cost basis') }}</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="unlockCostBasis({{ $holding->id }})" data-test="unlock-cost-basis">{{ __('Unlock') }}</flux:button>
            </div>

            <div class="mt-4 space-y-2">
                <flux:input wire:model.live.debounce.400ms="tickerQuery" label="{{ __('Remap to another ticker') }}" data-test="remap-search"/>
                <flux:text size="sm">{{ __('Remapping also moves any genuine trades already recorded on the target security.') }}</flux:text>
                @foreach ($this->searchResults as $result)
                    <div class="flex items-center justify-between" wire:key="remap-{{ $result->key() }}">
                        <span>{{ $result->ticker }} · {{ $result->name }}</span>
                        <flux:button size="xs" wire:click="remap({{ $holding->id }}, @js('new:'.$result->key()))" data-test="remap-choose">{{ __('Remap') }}</flux:button>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 flex gap-2">
                <flux:button size="sm" variant="ghost" wire:click="resetMapping({{ $holding->id }})" data-test="reset-mapping">{{ __('Reset mapping') }}</flux:button>
                <flux:button size="sm" variant="danger" wire:click="deleteHolding({{ $holding->id }})" wire:confirm="{{ __('Delete this holding?') }}" data-test="delete-holding">{{ __('Delete holding') }}</flux:button>
            </div>

            <flux:table class="mt-4" data-test="detail-trades">
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column>{{ __('Activity') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Quantity') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Price') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->detailTrades as $trade)
                        <flux:table.row wire:key="trade-{{ $trade->id }}">
                            <flux:table.cell>{{ $trade->date->format('d/m/Y') }}</flux:table.cell>
                            <flux:table.cell>{{ $trade->activity->label() }}</flux:table.cell>
                            <flux:table.cell align="end">{{ Decimal::trim($trade->quantity) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $money($trade->price) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $money($trade->amount) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </x-cib.card>
    @endif

    <x-cib.card data-test="trade-form">
        <div class="cib-label mb-3">{{ __('Record a trade') }}</div>
        <div class="grid gap-3 sm:grid-cols-3">
            <flux:select wire:model.live="activity" label="{{ __('Activity') }}" data-test="trade-activity">
                @foreach ($activities as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </flux:select>
            <flux:input type="date" wire:model="tradeDate" label="{{ __('Date') }}" data-test="trade-date"/>
            @if ($activity === TradeActivity::Deposit->value || $activity === TradeActivity::Withdrawal->value)
                <flux:select wire:model="counterAccountId" label="{{ __('Other account (optional)') }}">
                    <option value="">{{ __('None') }}</option>
                    @foreach ($this->counterAccounts as $other)
                        <option value="{{ $other->id }}">{{ $other->name }}</option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="amount" label="{{ __('Amount') }}" placeholder="0.00" data-test="trade-amount"/>
            @else
                @if (TradeActivity::tryFrom($activity)?->needsSecurity() ?? true)
                    <flux:select wire:model="securityRef" label="{{ __('Security') }}" data-test="trade-security">
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach ($this->knownSecurities as $known)
                            <option value="id:{{ $known->id }}">{{ $known->ticker }}</option>
                        @endforeach
                        @foreach ($this->searchResults as $result)
                            <option value="new:{{ $result->key() }}">{{ $result->ticker }} · {{ $result->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model.live.debounce.400ms="tickerQuery" label="{{ __('Search tickers') }}" data-test="trade-ticker-search"/>
                @endif
                @if (in_array($activity, [TradeActivity::Dividend->value, TradeActivity::Interest->value, TradeActivity::Fee->value], true))
                    <flux:input wire:model="amount" label="{{ __('Amount') }}" placeholder="0.00"/>
                @else
                    <flux:input wire:model="quantity" label="{{ __('Quantity') }}" data-test="trade-quantity"/>
                    <flux:input wire:model="price" label="{{ __('Price') }}" placeholder="0.00" data-test="trade-price"/>
                    <flux:input wire:model="fee" label="{{ __('Fee') }}" placeholder="0.00" data-test="trade-fee"/>
                @endif
            @endif
        </div>
        <div class="mt-3">
            <flux:button variant="primary" wire:click="recordTrade" data-test="trade-submit">{{ __('Record') }}</flux:button>
        </div>
    </x-cib.card>
</div>
