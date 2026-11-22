@use('App\Casts\MoneyCast')

<section>
    <x-cib.sec-head title="Next 3 planned"/>
    @forelse ($dashboard->nextThreePlanned as $row)
        <div class="planned-row flex items-center justify-between py-1 text-sm">
            <span class="font-medium">{{ $row['planned']->description }}</span>
            <span class="text-gray-600">{{ $row['next']->format('M j') }}</span>
            <span class="font-bold">{{ MoneyCast::format(abs((int) $row['planned']->amount)) }}</span>
        </div>
    @empty
        <p class="text-sm text-gray-500">Nothing planned.</p>
    @endforelse
</section>
