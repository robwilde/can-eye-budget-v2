<div class="mx-auto max-w-6xl space-y-6" data-test="admin-audit-log">
    <div class="space-y-2">
        <flux:heading size="xl">{{ __('Audit trail') }}</flux:heading>
        <flux:text>{{ __('Who did what, newest first. Entries hold the action, the record it touched and the outcome, never what was sent.') }}</flux:text>
    </div>

    <div class="grid gap-4 md:grid-cols-5">
        <flux:input wire:model.live.debounce.400ms="user" :label="__('User (name or email)')" data-test="audit-filter-user" />
        <flux:input wire:model.live.debounce.400ms="action" :label="__('Action contains')" data-test="audit-filter-action" />
        <flux:select wire:model.live="outcome" :label="__('Outcome')" data-test="audit-filter-outcome">
            <flux:select.option value="">{{ __('Any') }}</flux:select.option>
            @foreach ($outcomes as $option)
                <flux:select.option value="{{ $option->value }}">{{ ucfirst($option->value) }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live="from" type="date" :label="__('From')" data-test="audit-filter-from" />
        <flux:input wire:model.live="to" type="date" :label="__('To')" data-test="audit-filter-to" />
    </div>

    <div class="overflow-x-auto rounded-lg border-2 border-cib-black bg-white">
        <table class="w-full text-start text-sm">
            <thead class="border-b-2 border-cib-black bg-zinc-50 text-xs uppercase text-zinc-500">
                <tr>
                    <th class="px-4 py-2 text-start">{{ __('When') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('User') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Action') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Record') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Outcome') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->events as $event)
                    <tr class="border-b border-zinc-200" wire:key="audit-{{ $event->id }}" data-test="audit-row">
                        <td class="px-4 py-2 whitespace-nowrap text-zinc-500">{{ $event->created_at->format('j M Y H:i:s') }}</td>
                        <td class="px-4 py-2">{{ $event->user?->email ?? '—' }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ $event->action }}</td>
                        <td class="px-4 py-2 text-zinc-500">{{ $event->subject_type !== null ? $event->subject_type.' #'.$event->subject_id : '' }}</td>
                        <td class="px-4 py-2 uppercase">{{ $event->outcome->value }}</td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-6 text-zinc-500" colspan="5">{{ __('No events match.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $this->events->links() }}
</div>
