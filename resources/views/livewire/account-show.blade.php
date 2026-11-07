@php
    use App\Casts\MoneyCast;
    $detail = $account->detail;
    $schedule = $this->schedule;
@endphp
<div class="space-y-6" data-test="account-show">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="lg">{{ $account->name }}</flux:heading>
            <flux:subheading>{{ $account->type->label() }} · {{ MoneyCast::format($account->balance) }}</flux:subheading>
        </div>
        <flux:button variant="ghost" size="sm" icon="arrow-left" href="{{ route('accounts') }}" wire:navigate>{{ __('Accounts') }}</flux:button>
    </div>

    @if (count($tabs) > 1)
        <x-cib.filter-toggle :options="$tabs" :selected="$tab" wire-model="tab" data-test="account-tabs"/>
    @endif

    @if ($tab === 'schedule')
        <x-cib.card data-test="account-schedule">
            @if ($schedule === null)
                <flux:text data-test="account-schedule-empty">{{ __('Add the original principal, rate, term and start date to see a repayment schedule.') }}</flux:text>
            @else
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <div class="cib-label">{{ __('Monthly payment') }}</div>
                        <div data-test="schedule-payment">{{ MoneyCast::format($schedule->payment) }}</div>
                    </div>
                    <div>
                        <div class="cib-label">{{ __('Total interest') }}</div>
                        <div data-test="schedule-interest">{{ MoneyCast::format($schedule->totalInterest) }}</div>
                    </div>
                    <div>
                        <div class="cib-label">{{ __('Projected payoff') }}</div>
                        <div data-test="schedule-payoff">{{ $schedule->payoffDate->format('d/m/Y') }}</div>
                    </div>
                </div>

                <flux:table class="mt-4">
                    <flux:table.columns>
                        <flux:table.column>#</flux:table.column>
                        <flux:table.column>{{ __('Due') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Payment') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Interest') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Principal') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Remaining') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($schedule->rows as $row)
                            <flux:table.row wire:key="schedule-row-{{ $row->number }}">
                                <flux:table.cell>{{ $row->number }}</flux:table.cell>
                                <flux:table.cell>{{ $row->dueOn->format('d/m/Y') }}</flux:table.cell>
                                <flux:table.cell align="end">{{ MoneyCast::format($row->payment) }}</flux:table.cell>
                                <flux:table.cell align="end">{{ MoneyCast::format($row->interest) }}</flux:table.cell>
                                <flux:table.cell align="end">{{ MoneyCast::format($row->principal) }}</flux:table.cell>
                                <flux:table.cell align="end">{{ MoneyCast::format($row->remaining) }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-cib.card>
    @else
        <x-cib.card data-test="account-overview">
            <dl class="grid gap-4 sm:grid-cols-2">
                @if ($account->institution)
                    <div><dt class="cib-label">{{ __('Institution') }}</dt><dd>{{ $account->institution }}</dd></div>
                @endif
                <div><dt class="cib-label">{{ __('Balance') }}</dt><dd>{{ MoneyCast::format($account->balance) }}</dd></div>
                @if ($account->credit_limit !== null)
                    <div><dt class="cib-label">{{ __('Credit limit') }}</dt><dd>{{ MoneyCast::format($account->credit_limit) }}</dd></div>
                @endif
                @if ($account->type->amortises() && $detail !== null)
                    @if ($detail->original_principal !== null)
                        <div><dt class="cib-label">{{ __('Original principal') }}</dt><dd>{{ MoneyCast::format($detail->original_principal) }}</dd></div>
                    @endif
                    @if ($detail->start_date !== null)
                        <div><dt class="cib-label">{{ __('Start date') }}</dt><dd>{{ $detail->start_date->format('d/m/Y') }}</dd></div>
                    @endif
                    @if ($detail->rate !== null)
                        <div><dt class="cib-label">{{ __('Rate') }}</dt><dd>{{ $detail->rate }}% {{ $detail->rate_type?->label() }}</dd></div>
                    @endif
                    @if ($detail->term_months !== null)
                        <div><dt class="cib-label">{{ __('Term') }}</dt><dd>{{ __(':months months', ['months' => $detail->term_months]) }}</dd></div>
                    @endif
                    @if ($detail->down_payment !== null)
                        <div><dt class="cib-label">{{ __('Down payment') }}</dt><dd>{{ MoneyCast::format($detail->down_payment) }}</dd></div>
                    @endif
                    @if ($detail->insurance !== null)
                        <div><dt class="cib-label">{{ __('Insurance') }}</dt><dd>{{ MoneyCast::format($detail->insurance) }}</dd></div>
                    @endif
                @elseif ($account->type === \App\Enums\AccountClass::CreditCard && $detail !== null)
                    @if ($detail->minimum_payment !== null)
                        <div><dt class="cib-label">{{ __('Minimum payment') }}</dt><dd>{{ MoneyCast::format($detail->minimum_payment) }}</dd></div>
                    @endif
                    @if ($detail->annual_fee !== null)
                        <div><dt class="cib-label">{{ __('Annual fee') }}</dt><dd>{{ MoneyCast::format($detail->annual_fee) }}</dd></div>
                    @endif
                @endif
            </dl>
        </x-cib.card>
    @endif
</div>
