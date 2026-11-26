@php
    use App\Casts\MoneyCast;
    $report = $this->report;
@endphp

<div class="mx-auto max-w-6xl space-y-6" data-test="admin-llm-usage">
    <div class="space-y-2">
        <flux:heading size="xl">{{ __('LLM usage') }}</flux:heading>
        <flux:text>{{ __('Tokens and cost of the AI features, in the instance currency. Prompts and replies are never stored here.') }}</flux:text>
    </div>

    <div class="flex flex-wrap items-end gap-4">
        <flux:input wire:model.live="from" type="date" :label="__('From')" data-test="usage-filter-from" />
        <flux:input wire:model.live="to" type="date" :label="__('To')" data-test="usage-filter-to" />
        <div class="text-sm" data-test="usage-total">
            <span class="text-zinc-500">{{ __('Total') }}</span>
            <span class="ms-2 text-lg font-bold">{{ MoneyCast::format($report['total']) }}</span>
            <span class="ms-2 text-zinc-500">{{ trans_choice(':count call|:count calls', $report['calls']) }}</span>
        </div>
    </div>

    @foreach ([
        ['key' => 'operations', 'title' => __('Per operation'), 'column' => __('Operation')],
        ['key' => 'models', 'title' => __('Per model'), 'column' => __('Model')],
        ['key' => 'users', 'title' => __('Per user'), 'column' => __('User')],
    ] as $section)
        <div class="space-y-2" data-test="usage-{{ $section['key'] }}">
            <flux:heading>{{ $section['title'] }}</flux:heading>
            <div class="overflow-x-auto rounded-lg border-2 border-cib-black bg-white">
                <table class="w-full text-start text-sm">
                    <thead class="border-b-2 border-cib-black bg-zinc-50 text-xs uppercase text-zinc-500">
                        <tr>
                            <th class="px-4 py-2 text-start">{{ $section['column'] }}</th>
                            <th class="px-4 py-2 text-end">{{ __('Calls') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('Prompt tokens') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('Completion tokens') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('Cost') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report[$section['key']] as $row)
                            <tr class="border-b border-zinc-200" data-test="usage-row">
                                <td class="px-4 py-2">{{ $row['label'] }}</td>
                                <td class="px-4 py-2 text-end">{{ number_format($row['calls']) }}</td>
                                <td class="px-4 py-2 text-end">{{ number_format($row['prompt_tokens']) }}</td>
                                <td class="px-4 py-2 text-end">{{ number_format($row['completion_tokens']) }}</td>
                                <td class="px-4 py-2 text-end font-bold" data-test="usage-cost">{{ MoneyCast::format($row['cost']) }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-4 py-6 text-zinc-500" colspan="5">{{ __('No usage in this range.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
</div>
