@use('App\Casts\MoneyCast')

@php($flows = $this->flows)

<div class="space-y-2" data-testid="report-investment-flows">
    <div class="grid gap-3 sm:grid-cols-3">
        <x-cib.money-card label="Contributions" :amount="$flows['contributions']"/>
        <x-cib.money-card label="Withdrawals" :amount="$flows['withdrawals']"/>
        <x-cib.money-card label="Net" :amount="$flows['net']"/>
    </div>
</div>
