@php
    use App\Casts\MoneyCast;
    use App\Enums\TransactionStatus;
    $reconciliation = $this->reconciliation;
    $closed = $reconciliation !== null && ! $reconciliation->isOpen();
    $showUpload = $reconciliation === null || $uploading;
@endphp
<div class="space-y-6" data-testid="reconcile-statement">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="lg">{{ __('Reconcile :account', ['account' => $account->name]) }}</flux:heading>
            <flux:subheading>
                {{ __('Check the bank statement for a month against what the feed delivered, then close the month.') }}
            </flux:subheading>
        </div>

        <flux:select wire:model.live="month" :label="__('Month')" class="max-w-48" data-testid="reconcile-month">
            @foreach ($this->monthOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </flux:select>
    </div>

    @if ($errorMessage)
        <flux:callout variant="danger" icon="exclamation-triangle" data-testid="reconcile-error">
            <flux:callout.text>{{ $errorMessage }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($closed)
        <flux:callout icon="lock-closed" data-testid="reconcile-closed-banner">
            <flux:callout.heading>{{ __('This month is closed') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Closed :date. Reopen it to make changes.', ['date' => $reconciliation->closed_at?->format('d/m/Y H:i')]) }}
            </flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="reopen" size="sm" data-testid="reconcile-reopen">{{ __('Reopen') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    {{-- STEP 1: upload + mapping --}}
    @if ($showUpload && ! $closed)
        <x-cib.card data-testid="reconcile-upload">
            <flux:heading size="lg">{{ __('Upload the statement') }}</flux:heading>
            <flux:text size="sm" class="mt-1">
                {{ __('Export this month\'s statement from your bank as CSV. Rows outside the month are counted but not reconciled.') }}
            </flux:text>

            <div class="mt-4 space-y-4">
                <div>
                    <flux:label>{{ __('CSV file') }}</flux:label>
                    <input
                        type="file"
                        wire:model="file"
                        accept=".csv,text/csv"
                        data-testid="reconcile-file-input"
                        class="mt-1 block w-full text-sm"
                    />
                    @error('file') <flux:text class="text-cib-red-600 mt-1 text-sm">{{ $message }}</flux:text> @enderror
                </div>

                <div class="flex items-center gap-3">
                    <flux:button wire:click="uploadFile" wire:loading.attr="disabled" data-testid="reconcile-detect">
                        <flux:icon.loading wire:loading wire:target="uploadFile,file" class="size-4"/>
                        {{ __('Detect columns') }}
                    </flux:button>
                    @if ($reconciliation !== null)
                        <flux:button wire:click="cancelUpload" variant="ghost">{{ __('Cancel') }}</flux:button>
                    @endif
                </div>
            </div>

            @if ($headers !== [])
                <div class="mt-6 grid grid-cols-1 gap-3 md:grid-cols-2" data-testid="reconcile-mapping">
                    @foreach ($fields as $field => $label)
                        <flux:select wire:model.live="mapping.{{ $field }}" :label="$label" :data-testid="'reconcile-mapping-' . $field">
                            <option value="">— unmapped —</option>
                            @foreach ($headers as $header)
                                <option value="{{ $header }}">{{ $header }}</option>
                            @endforeach
                        </flux:select>
                    @endforeach
                </div>
                @error('mapping.date') <flux:text class="text-cib-red-600 mt-1 text-sm">{{ $message }}</flux:text> @enderror
                @error('mapping.amount') <flux:text class="text-cib-red-600 mt-1 text-sm">{{ $message }}</flux:text> @enderror

                @if ($previewRows !== [])
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-fg-3">
                                    <th class="py-2 pr-3">{{ __('Date') }}</th>
                                    <th class="py-2 pr-3">{{ __('Description') }}</th>
                                    <th class="py-2 pr-3 text-right">{{ __('Amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($previewRows as $row)
                                    <tr class="border-t border-cib-black/10">
                                        <td class="py-2 pr-3">{{ $row->postDate->format('d/m/Y') }}</td>
                                        <td class="py-2 pr-3">{{ \Illuminate\Support\Str::limit($row->description, 60) }}</td>
                                        <td class="py-2 pr-3 text-right tabular-nums">{{ MoneyCast::format($row->amount) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="mt-4">
                    <flux:button variant="primary" wire:click="reconcile" wire:loading.attr="disabled" data-testid="reconcile-submit">
                        <flux:icon.loading wire:loading wire:target="reconcile" class="size-4"/>
                        {{ __('Reconcile') }}
                    </flux:button>
                </div>
            @endif
        </x-cib.card>
    @endif

    {{-- STEP 2: review --}}
    @if ($reconciliation !== null && ! $uploading)
        <x-cib.card data-testid="reconcile-summary">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading size="lg">{{ $reconciliation->original_filename }}</flux:heading>
                @unless ($closed)
                    <flux:button wire:click="startUpload" size="sm" variant="ghost" data-testid="reconcile-new-file">
                        {{ __('Upload a new file') }}
                    </flux:button>
                @endunless
            </div>
            <div class="mt-4 flex flex-wrap gap-3">
                <x-cib.stat-pill tone="neutral" data-testid="reconcile-chip-lines">{{ $statementLineCount }} {{ __('statement lines') }}</x-cib.stat-pill>
                <x-cib.stat-pill tone="income" data-testid="reconcile-chip-matched">{{ $matched->count() }} {{ __('matched') }}</x-cib.stat-pill>
                <x-cib.stat-pill tone="posted" data-testid="reconcile-chip-statement-only">{{ $statementOnly->count() }} {{ __('statement only') }}</x-cib.stat-pill>
                <x-cib.stat-pill tone="planned" data-testid="reconcile-chip-feed-only">{{ $feedOnly->count() }} {{ __('feed only') }}</x-cib.stat-pill>
                @if ($reconciliation->lines_outside_period > 0)
                    <x-cib.stat-pill tone="neutral" data-testid="reconcile-chip-outside">{{ $reconciliation->lines_outside_period }} {{ __('outside the month') }}</x-cib.stat-pill>
                @endif
                <x-cib.stat-pill tone="neutral" data-testid="reconcile-chip-debits">
                    {{ __('Debits') }} {{ MoneyCast::format($reconciliation->statement_debit_total) }} {{ __('statement') }} / {{ MoneyCast::format($matchedDebits) }} {{ __('matched') }}
                </x-cib.stat-pill>
                <x-cib.stat-pill tone="neutral" data-testid="reconcile-chip-credits">
                    {{ __('Credits') }} {{ MoneyCast::format($reconciliation->statement_credit_total) }} {{ __('statement') }} / {{ MoneyCast::format($matchedCredits) }} {{ __('matched') }}
                </x-cib.stat-pill>
                @if ($reconciliation->closing_balance !== null)
                    <x-cib.stat-pill tone="neutral" data-testid="reconcile-chip-closing">{{ __('Closing balance') }} {{ MoneyCast::format($reconciliation->closing_balance) }}</x-cib.stat-pill>
                @endif
            </div>
        </x-cib.card>

        {{-- Statement only --}}
        <x-cib.card data-testid="reconcile-section-statement-only">
            <flux:heading size="lg">{{ __('Statement only') }}</flux:heading>
            <flux:text size="sm" class="mt-1">{{ __('On the statement but not in the feed. Add it, link it to a feed row, or ignore it with a note.') }}</flux:text>

            <div class="mt-3 divide-y divide-cib-black/10">
                @forelse ($statementOnly as $line)
                    @php
                        $targets = $feedOnly->sortBy(fn ($f) => $f->amount === $line->amount ? 0 : 1)->values();
                    @endphp
                    <div class="space-y-2 py-3" wire:key="line-{{ $line->id }}" data-testid="reconcile-line-{{ $line->id }}">
                        @include('livewire.partials.reconcile-line', ['line' => $line, 'closed' => $closed])

                        @if ($line->resolution !== null)
                            <flux:text size="sm" class="text-fg-3">
                                {{ $line->resolution->label() }}@if ($line->note): {{ $line->note }}@endif
                            </flux:text>
                        @elseif (! $closed)
                            <div class="flex flex-wrap items-center gap-2 ps-8" x-data="{ target: '', note: '' }">
                                <flux:button size="sm" wire:click="import({{ $line->id }})" data-testid="reconcile-import-{{ $line->id }}">
                                    {{ __('Add to account') }}
                                </flux:button>

                                @if ($targets->isNotEmpty())
                                    <div class="w-72">
                                        <flux:select size="sm" x-model="target" data-testid="reconcile-link-target-{{ $line->id }}">
                                            <option value="">{{ __('Link to a feed row…') }}</option>
                                            @foreach ($targets as $target)
                                                <option value="{{ $target->id }}">
                                                    {{ $target->post_date->format('d/m') }} · {{ \Illuminate\Support\Str::limit($target->description, 40) }} · {{ MoneyCast::format($target->amount) }}
                                                </option>
                                            @endforeach
                                        </flux:select>
                                    </div>
                                    <flux:button size="sm" x-bind:disabled="target === ''" x-on:click="$wire.link({{ $line->id }}, Number(target))" data-testid="reconcile-link-{{ $line->id }}">
                                        {{ __('Link') }}
                                    </flux:button>
                                @endif

                                <div class="w-56">
                                    <flux:input size="sm" x-model="note" :placeholder="__('Why ignore?')" data-testid="reconcile-ignore-note-{{ $line->id }}"/>
                                </div>
                                <flux:button size="sm" variant="ghost" x-on:click="$wire.ignore({{ $line->id }}, note)" data-testid="reconcile-ignore-{{ $line->id }}">
                                    {{ __('Ignore') }}
                                </flux:button>
                            </div>
                            @error('ignore.' . $line->id) <flux:text class="text-cib-red-600 text-sm">{{ $message }}</flux:text> @enderror
                        @endif
                    </div>
                @empty
                    <flux:text size="sm" class="py-3 text-fg-3">{{ __('Nothing — every statement line is in the feed.') }}</flux:text>
                @endforelse
            </div>
        </x-cib.card>

        {{-- Feed only --}}
        <x-cib.card data-testid="reconcile-section-feed-only">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg">{{ __('Feed only') }}</flux:heading>
                @unless ($closed)
                    <flux:button size="sm" variant="ghost" wire:click="tickAll('feed_only')" data-testid="reconcile-tick-all-feed-only">{{ __('Tick all feed-only') }}</flux:button>
                @endunless
            </div>
            <flux:text size="sm" class="mt-1">{{ __('In the feed but not on the statement. Tick to acknowledge.') }}</flux:text>

            <div class="mt-3 divide-y divide-cib-black/10">
                @forelse ($feedOnly as $line)
                    <div class="py-3" wire:key="line-{{ $line->id }}" data-testid="reconcile-line-{{ $line->id }}">
                        @include('livewire.partials.reconcile-line', [
                            'line' => $line,
                            'closed' => $closed,
                            'pending' => $line->transaction?->status === TransactionStatus::Pending,
                        ])
                    </div>
                @empty
                    <flux:text size="sm" class="py-3 text-fg-3">{{ __('Nothing — the feed has no extra rows this month.') }}</flux:text>
                @endforelse
            </div>
        </x-cib.card>

        {{-- Matched --}}
        <x-cib.card data-testid="reconcile-section-matched">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg">{{ __('Matched') }}</flux:heading>
                @unless ($closed)
                    <flux:button size="sm" variant="ghost" wire:click="tickAll('matched')" data-testid="reconcile-tick-all-matched">{{ __('Tick all matched') }}</flux:button>
                @endunless
            </div>

            <div class="mt-3 divide-y divide-cib-black/10">
                @forelse ($matched as $line)
                    <div class="py-3" wire:key="line-{{ $line->id }}" data-testid="reconcile-line-{{ $line->id }}">
                        @include('livewire.partials.reconcile-line', ['line' => $line, 'closed' => $closed, 'openTransaction' => true])
                    </div>
                @empty
                    <flux:text size="sm" class="py-3 text-fg-3">{{ __('No matched lines yet.') }}</flux:text>
                @endforelse
            </div>
        </x-cib.card>

        @unless ($closed)
            <div class="flex items-center gap-3">
                <flux:button
                    variant="primary"
                    wire:click="close"
                    :disabled="! $reconciliation->canClose()"
                    data-testid="reconcile-close"
                >
                    {{ __('Close month') }}
                </flux:button>
                @unless ($reconciliation->canClose())
                    <flux:text size="sm" class="text-fg-3">{{ __('Tick every line to close the month.') }}</flux:text>
                @endunless
            </div>
        @endunless
    @endif
</div>
