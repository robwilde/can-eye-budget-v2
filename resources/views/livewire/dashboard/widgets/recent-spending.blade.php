@use('App\Casts\MoneyCast')

<section>
    <x-cib.sec-head title="Spend last 7 days"/>
    <div class="spend-total text-2xl font-bold">{{ MoneyCast::format($dashboard->spendLast7Days['sum']) }}</div>
    <x-cib.spark
            :values="$dashboard->spendLast7Days['sparkline']"
            :payday-indexes="$dashboard->spendLast7Days['paydayIndexes']"
    />
</section>
