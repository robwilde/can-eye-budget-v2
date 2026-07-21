@use('App\Casts\MoneyCast')

@props(['receipt'])

@php
    $total = is_numeric($receipt['total'] ?? null) ? (int) $receipt['total'] : null;
    $date = is_string($receipt['date'] ?? null) ? $receipt['date'] : null;
    $method = is_string($receipt['method'] ?? null) ? $receipt['method'] : null;
    $last4 = is_string($receipt['last4'] ?? null) ? $receipt['last4'] : null;
    $items = is_array($receipt['items'] ?? null) ? $receipt['items'] : [];
    $type = is_string($receipt['type'] ?? null) ? $receipt['type'] : null;
    $seller = is_string($receipt['seller'] ?? null) ? $receipt['seller'] : null;
    $balance = is_numeric($receipt['balance'] ?? null) ? (int) $receipt['balance'] : null;
    $loanReference = is_string($receipt['loanReference'] ?? null) ? $receipt['loanReference'] : null;
@endphp

<div class="receipt">
    @if($total !== null || $date !== null || $method !== null)
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

    @if($seller !== null || $type !== null || $balance !== null || $loanReference !== null)
        <div class="receipt-fields">
            @if($seller !== null)
                <div class="receipt-field"><span class="receipt-field-label">Seller</span><span class="receipt-field-value receipt-seller">{{ $seller }}</span></div>
            @endif
            @if($type !== null)
                <div class="receipt-field"><span class="receipt-field-label">Type</span><span class="receipt-field-value">{{ $type }}</span></div>
            @endif
            @if($balance !== null)
                <div class="receipt-field"><span class="receipt-field-label">Balance</span><span class="receipt-field-value">{{ MoneyCast::format($balance) }}</span></div>
            @endif
            @if($loanReference !== null)
                <div class="receipt-field"><span class="receipt-field-label">Loan ref</span><span class="receipt-field-value receipt-ref">{{ $loanReference }}</span></div>
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
