@use('App\Casts\MoneyCast')

@props(['receipt'])

@php
    $total = is_numeric($receipt['total'] ?? null) ? (int) $receipt['total'] : null;
    $date = is_string($receipt['date'] ?? null) ? $receipt['date'] : null;
    $method = is_string($receipt['method'] ?? null) ? $receipt['method'] : null;
    $last4 = is_string($receipt['last4'] ?? null) ? $receipt['last4'] : null;
    $items = is_array($receipt['items'] ?? null) ? $receipt['items'] : [];
@endphp

<div class="receipt">
    @if($total !== null || $date !== null)
        <div class="receipt-head">
            @if($total !== null)
                <span class="receipt-total">{{ MoneyCast::format($total) }}</span>
            @endif
            @if($date !== null)
                <span class="receipt-date">{{ $date }}</span>
            @endif
            @if($method !== null)
                <span class="receipt-method">{{ $method }}@if($last4) ···· {{ $last4 }}@endif</span>
            @endif
        </div>
    @endif

    @if($items !== [])
        <div class="receipt-items">
            @foreach($items as $item)
                <div class="receipt-item">
                    <span class="receipt-merchant">{{ $item['merchant'] ?? '—' }}</span>
                    @if(! empty($item['installment']))
                        <span class="receipt-installment">{{ $item['installment'] }}</span>
                    @endif
                    <span class="receipt-amount">{{ MoneyCast::format((int) ($item['amount'] ?? 0)) }}</span>
                </div>
            @endforeach
        </div>
    @endif
</div>
