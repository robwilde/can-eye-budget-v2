@php
    use App\Enums\TransactionDirection;
    use App\Services\GmailService;
    use Carbon\CarbonImmutable;
    use App\Support\AmountParser;
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">
                @if($categoryName)
                    {{ $categoryName }} Transactions
                @else
                    All Transactions
                @endif
            </flux:heading>
            <flux:text class="mt-1">
                Showing
                @if($direction === 'incoming')
                    incoming
                @elseif($direction === 'outgoing')
                    outgoing
                @else
                    all
                @endif
                transactions — {{ $periodLabel }}
            </flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <flux:select wire:model.live="period" size="sm" class="w-auto">
                <flux:select.option value="7d">Last 7 Days</flux:select.option>
                <flux:select.option value="this-month">This Month</flux:select.option>
                <flux:select.option value="3m">Last 3 Months</flux:select.option>
                <flux:select.option value="6m">Last 6 Months</flux:select.option>
                <flux:select.option value="1y">Last Year</flux:select.option>
                @if($hasPayCycle)
                    <flux:select.option value="pay-cycle">Pay Cycle</flux:select.option>
                @endif
                <flux:select.option value="all">All Time</flux:select.option>
                <flux:select.option value="custom">Custom Range</flux:select.option>
            </flux:select>
            @if($showCustomRange)
                <flux:input type="date" wire:model.live="from" size="sm" class="w-auto"/>
                <flux:input type="date" wire:model.live="to" size="sm" class="w-auto"/>
            @endif
            <flux:button variant="ghost" icon="arrow-left" href="{{ route('dashboard') }}" size="sm">Dashboard</flux:button>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <x-cib.filter-toggle
            :options="[
                ['value' => 'all', 'label' => 'All'],
                ['value' => 'outgoing', 'label' => 'Outgoing', 'tone' => 'out'],
                ['value' => 'incoming', 'label' => 'Incoming', 'tone' => 'inc'],
            ]"
            :selected="$direction"
            wire-model="direction"
        />

        @if($accounts->count() > 1)
            <x-cib.filter-toggle
                :options="collect([['value' => null, 'label' => 'All Accounts']])
                    ->concat($accounts->map(fn ($acc) => ['value' => $acc->id, 'label' => $acc->name]))
                    ->all()"
                :selected="$account"
                wire-model="account"
            />
        @endif

        <x-cib.filter-toggle
            :options="[
                ['value' => 'all', 'label' => 'All'],
                ['value' => 'planned', 'label' => 'Planned'],
                ['value' => 'unplanned', 'label' => 'Unplanned'],
            ]"
            :selected="$planned"
            wire-model="planned"
        />

        <x-cib.filter-toggle
            :options="[
                ['value' => 'all', 'label' => 'All'],
                ['value' => 'categorised', 'label' => 'Categorised'],
                ['value' => 'uncategorised', 'label' => 'Uncategorised'],
            ]"
            :selected="$categorised"
            wire-model="categorised"
        />
    </div>

    <flux:input wire:model.live.debounce.300ms="search" placeholder="Search transactions..." icon="magnifying-glass" size="sm"/>

    @if($transactions->isEmpty())
        <x-cib.empty-state
            icon="banknotes"
            title="No transactions found"
            description="No transactions match your current filters."
        >
            <x-slot:action>
                <flux:button variant="primary" icon="arrow-left" href="{{ route('dashboard') }}">
                    Back to Dashboard
                </flux:button>
            </x-slot:action>
        </x-cib.empty-state>
    @else
        <div class="relative">
            <div wire:loading class="absolute inset-0 z-10 flex items-center justify-center bg-white/60 dark:bg-zinc-900/60">
                <flux:icon.arrow-path class="size-6 animate-spin text-zinc-400"/>
            </div>

            <div class="flex items-center gap-4 px-4 py-2">
                <button wire:click="sort('description')"
                        class="cib-label flex min-w-0 flex-1 items-center gap-1 text-left">
                    Description
                    @if($sortBy === 'description')
                        <flux:icon :name="$sortDir === 'asc' ? 'chevron-up' : 'chevron-down'" class="size-3"/>
                    @endif
                </button>
                @if($account === null)
                    <span class="cib-label w-32">Account</span>
                @endif
                <button wire:click="sort('post_date')"
                        class="cib-label flex w-24 items-center gap-1">
                    Date
                    @if($sortBy === 'post_date')
                        <flux:icon :name="$sortDir === 'asc' ? 'chevron-up' : 'chevron-down'" class="size-3"/>
                    @endif
                </button>
                <button wire:click="sort('amount')"
                        class="cib-label flex w-28 items-center justify-end gap-1">
                    Amount
                    @if($sortBy === 'amount')
                        <flux:icon :name="$sortDir === 'asc' ? 'chevron-up' : 'chevron-down'" class="size-3"/>
                    @endif
                </button>
            </div>

            <div class="agenda">
                @foreach($grouped as $dateKey => $dayTxns)
                    <section wire:key="group-{{ $dateKey }}" class="agenda-group">
                        <x-cib.sec-head :title="CarbonImmutable::parse($dateKey)->format('j D M')"/>
                        <div class="day-card">
                            @foreach($dayTxns as $transaction)
                                @php
                                    $tone = $transaction->direction === TransactionDirection::Credit ? 'inc' : 'out';
                                    $metaParts = array_filter([
                                        $transaction->category?->name,
                                        $account === null ? $transaction->account?->name : null,
                                    ]);
                                    $isPlanned = $transaction->planned_transaction_id !== null;
                                @endphp
                                <x-cib.tx-row
                                    wire:key="txn-{{ $transaction->id }}"
                                    :name="$transaction->description"
                                    :amount="$transaction->amount"
                                    :tone="$tone"
                                    :icon="$transaction->category?->resolveIcon()"
                                    :click="'$dispatch(\'edit-transaction\', { id: ' . $transaction->id . ' })'"
                                >
                                    @if(! empty($metaParts) || $isPlanned || $transaction->emails->isNotEmpty() || $transaction->isSplit())
                                        <x-slot:meta>{{ implode(' · ', $metaParts) }}@if($isPlanned) <span class="pill plan">Planned</span>@endif@if($transaction->isSplit()) <span class="pill split">Split ({{ $transaction->splits->count() }})</span>@endif@if($transaction->emails->isNotEmpty()) <span class="pill email">{{ $transaction->emails->count() }} email{{ $transaction->emails->count() > 1 ? 's' : '' }}</span>@endif</x-slot:meta>
                                    @endif
                                    <x-slot:actions>
                                        @if($transaction->transfer_pair_id === null)
                                            <flux:button variant="ghost" size="sm" icon="scissors"
                                                         wire:click="toggleSplit({{ $transaction->id }})"
                                                         wire:loading.attr="disabled" wire:target="toggleSplit({{ $transaction->id }})"
                                                         data-testid="split-{{ $transaction->id }}" aria-label="Split transaction"/>
                                        @endif
                                        @if($gmailEnabled)
                                            <flux:button variant="ghost" size="sm" icon="envelope"
                                                         wire:click="scanEmail({{ $transaction->id }})"
                                                         wire:loading.attr="disabled" wire:target="scanEmail({{ $transaction->id }})"
                                                         data-testid="scan-email-{{ $transaction->id }}" aria-label="Scan email"/>
                                        @endif
                                    </x-slot:actions>
                                </x-cib.tx-row>
                                @if($emailPanelTxnId === $transaction->id)
                                    @php
                                        $linkedMessageIds = $transaction->emails->pluck('gmail_message_id');
                                        $newResults = collect($emailResults)->reject(fn (array $r): bool => $linkedMessageIds->contains($r['messageId']));
                                    @endphp
                                    <div wire:key="email-panel-{{ $transaction->id }}" class="email-scan-panel" data-testid="email-panel-{{ $transaction->id }}">
                                        @if($emailScanError)
                                            <p class="email-scan-error">{{ $emailScanError }}</p>
                                        @endif

                                        @if($transaction->emails->isNotEmpty())
                                            <div class="email-scan-section">
                                                <span class="cib-label">Linked emails</span>
                                                @foreach($transaction->emails as $email)
                                                    <div wire:key="linked-email-{{ $email->id }}" class="email-row">
                                                        <div class="email-row-body">
                                                            <div class="email-subject">{{ $email->subject }}</div>
                                                            <div class="email-meta">{{ $email->from_name ?? $email->from_address }}@if($email->email_date) · {{ $email->email_date->format('j M Y') }}@endif</div>
                                                            @if($email->snippet)
                                                                <div class="email-snippet">{{ $email->snippet }}</div>
                                                            @endif
                                                            <a href="{{ GmailService::deepLink($email->gmail_message_id) }}" target="_blank" rel="noopener" class="email-link">Open in Gmail</a>
                                                        </div>
                                                        <flux:button variant="ghost" size="sm" icon="x-mark"
                                                                     wire:click="unlinkEmail({{ $email->id }})"
                                                                     data-testid="unlink-email-{{ $email->id }}" aria-label="Unlink email"/>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif

                                        @if($newResults->isNotEmpty())
                                            <div class="email-scan-section">
                                                <span class="cib-label">Search results</span>
                                                @foreach($newResults as $index => $result)
                                                    <div wire:key="result-email-{{ $transaction->id }}-{{ $index }}" class="email-row">
                                                        <div class="email-row-body">
                                                            <div class="email-subject">{{ $result['subject'] }}</div>
                                                            <div class="email-meta">{{ $result['fromName'] ?? $result['fromAddress'] }}@if($result['date']) · {{ CarbonImmutable::parse($result['date'])->format('j M Y') }}@endif</div>
                                                            @if($result['snippet'])
                                                                <div class="email-snippet">{{ $result['snippet'] }}</div>
                                                            @endif
                                                            @php $resultMessageId = $result['messageId'] ?? null; @endphp
                                                            @if(is_string($resultMessageId) && trim($resultMessageId) !== '')
                                                                <a href="{{ GmailService::deepLink(trim($resultMessageId)) }}" target="_blank" rel="noopener" class="email-link">Open in Gmail</a>
                                                            @endif
                                                        </div>
                                                        <flux:button variant="ghost" size="sm" icon="link"
                                                                     wire:click="linkEmail({{ $transaction->id }}, {{ $index }})"
                                                                     data-testid="link-email-{{ $transaction->id }}-{{ $index }}">Link</flux:button>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif

                                        @if(! $emailScanError && $transaction->emails->isEmpty() && $newResults->isEmpty())
                                            <p class="email-empty">No matching emails found.</p>
                                        @endif
                                    </div>
                                @endif
                                @if($splitPanelTxnId === $transaction->id)
                                    @php
                                        $splitRemainderCents = abs((int) $transaction->amount) - collect($splitLines)->sum(fn (array $l): int => AmountParser::parse((string) ($l['amount'] ?? ''))->amount);
                                    @endphp
                                    <div wire:key="split-panel-{{ $transaction->id }}" class="split-panel" data-testid="split-panel-{{ $transaction->id }}">
                                        @if($splitError)
                                            <p class="split-error" data-testid="split-error-{{ $transaction->id }}">{{ $splitError }}</p>
                                        @endif

                                        <div class="split-lines">
                                            @foreach($splitLines as $index => $line)
                                                <div wire:key="split-line-{{ $transaction->id }}-{{ $index }}" class="split-line">
                                                    <flux:select wire:model="splitLines.{{ $index }}.category_id" size="sm" placeholder="Category">
                                                        @foreach($splitCategories as $cat)
                                                            <flux:select.option :value="$cat->id">{{ $cat->fullPath() }}</flux:select.option>
                                                        @endforeach
                                                    </flux:select>
                                                    <flux:input wire:model="splitLines.{{ $index }}.amount" size="sm" class="split-amount" inputmode="decimal" placeholder="0.00"/>
                                                    <flux:input wire:model="splitLines.{{ $index }}.notes" size="sm" class="split-notes" placeholder="Note (optional)"/>
                                                    <flux:button variant="ghost" size="sm" icon="x-mark"
                                                                 wire:click="removeSplitLine({{ $index }})"
                                                                 data-testid="remove-split-line-{{ $transaction->id }}-{{ $index }}" aria-label="Remove line"/>
                                                </div>
                                            @endforeach
                                        </div>

                                        <div class="split-foot">
                                            <div class="split-remainder">
                                                <span class="cib-label">Remainder</span>
                                                <span @class(['split-remainder-value', 'is-zero' => $splitRemainderCents === 0]) data-testid="split-remainder-{{ $transaction->id }}">{{ $formatMoney($splitRemainderCents) }}</span>
                                            </div>
                                            <div class="split-buttons">
                                                <flux:button variant="ghost" size="sm" icon="plus" wire:click="addSplitLine" data-testid="add-split-line-{{ $transaction->id }}">Add line</flux:button>
                                                <flux:button variant="ghost" size="sm" wire:click="assignRemainderToLast" data-testid="assign-remainder-{{ $transaction->id }}">Assign remainder</flux:button>
                                                @if($transaction->isSplit())
                                                    <flux:button variant="ghost" size="sm" icon="arrow-uturn-left" wire:click="unsplit({{ $transaction->id }})" data-testid="unsplit-{{ $transaction->id }}">Unsplit</flux:button>
                                                @endif
                                                <flux:button variant="primary" size="sm" wire:click="saveSplit" :disabled="$splitRemainderCents !== 0" data-testid="save-split-{{ $transaction->id }}">Save split</flux:button>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        </div>

        <x-cib.card class="mt-4">
            <div class="flex items-center justify-between gap-4">
                {{ $transactions->links() }}
            </div>
        </x-cib.card>
    @endif
</div>
