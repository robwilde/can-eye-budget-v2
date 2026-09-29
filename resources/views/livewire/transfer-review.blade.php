@php
    use App\Casts\MoneyCast;
@endphp

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Transfer review</flux:heading>
            <flux:text size="sm" class="mt-1 text-zinc-500">
                A suggested pair is not counted as a transfer until you confirm it. Confirm to link it and remember the pattern, or mark it as not a transfer.
            </flux:text>
        </div>
        <flux:button variant="ghost" icon="arrow-left" size="sm" href="{{ route('transactions') }}" wire:navigate>Transactions</flux:button>
    </div>

    <flux:card>
        <div class="space-y-4">
            <flux:heading size="lg">Suggested transfers <flux:badge size="sm" color="zinc">{{ $pairs->count() }}</flux:badge></flux:heading>

            @forelse($pairs as $pair)
                <div wire:key="pair-{{ $pair['debit']->id }}" class="rounded-lg border border-neutral-200 p-4">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="grid flex-1 gap-3 sm:grid-cols-2">
                            @foreach(['debit' => 'From', 'credit' => 'To'] as $leg => $label)
                                @php($row = $pair[$leg])
                                <div>
                                    <flux:text size="sm" class="text-zinc-500">{{ $label }} · {{ $row->account->name }} · {{ $row->post_date->format('j M Y') }}</flux:text>
                                    <div class="font-medium">{{ $row->description }}</div>
                                    <div class="tabular-nums">{{ MoneyCast::format($row->amount) }}</div>
                                </div>
                            @endforeach
                        </div>

                        <div class="flex gap-2">
                            <flux:button size="sm" variant="primary" wire:click="confirm({{ $pair['debit']->id }})">Confirm</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="notTransfer({{ $pair['debit']->id }})">Not a transfer</flux:button>
                        </div>
                    </div>
                </div>
            @empty
                <flux:text size="sm" class="text-zinc-500">No suggested transfers to review.</flux:text>
            @endforelse
        </div>
    </flux:card>

    <flux:card>
        <div class="space-y-4">
            <flux:heading size="lg">Unmatched transfers <flux:badge size="sm" color="zinc">{{ $unmatched->count() }}</flux:badge></flux:heading>
            <flux:text size="sm" class="text-zinc-500">Imported rows that look like a transfer (the description mentions it, or it is categorised as Transfer) with no opposite row. Link them to a hidden account such as a savings pot, or dismiss them.</flux:text>

            @forelse($unmatched as $row)
                <div wire:key="unmatched-{{ $row->id }}" class="rounded-lg border border-neutral-200 p-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <flux:text size="sm" class="text-zinc-500">{{ $row->account->name }} · {{ $row->post_date->format('j M Y') }}</flux:text>
                            <div class="font-medium">{{ $row->description }}</div>
                            <div class="tabular-nums">{{ MoneyCast::format($row->amount) }}</div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <flux:button size="sm" wire:click="startLinkHidden({{ $row->id }})">Link to hidden account</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="notTransfer({{ $row->id }})" data-testid="unmatched-not-transfer-{{ $row->id }}">Not a transfer</flux:button>
                        </div>
                    </div>

                    @if($linkingId === $row->id)
                        <div class="mt-4 space-y-3 border-t border-neutral-200 pt-4">
                            @if($hiddenAccounts->isNotEmpty())
                                <flux:field>
                                    <flux:label>Existing hidden account</flux:label>
                                    <flux:select wire:model="hiddenAccountId" size="sm">
                                        <flux:select.option value="">Create a new one…</flux:select.option>
                                        @foreach($hiddenAccounts as $hidden)
                                            <flux:select.option value="{{ $hidden->id }}">{{ $hidden->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </flux:field>
                            @endif

                            <flux:error name="hiddenAccountId" data-testid="hidden-link-error"/>

                            <flux:field>
                                <flux:label>New hidden account name</flux:label>
                                <flux:input wire:model="newAccountName" size="sm" placeholder="e.g. Holiday savings"/>
                                <flux:error name="newAccountName"/>
                            </flux:field>

                            <div class="flex gap-2">
                                <flux:button size="sm" variant="primary" wire:click="linkToHidden">Link</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="cancelLinkHidden">Cancel</flux:button>
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <flux:text size="sm" class="text-zinc-500">No unmatched transfers.</flux:text>
            @endforelse
        </div>
    </flux:card>

    <livewire:transfer-rule-list />
</div>
