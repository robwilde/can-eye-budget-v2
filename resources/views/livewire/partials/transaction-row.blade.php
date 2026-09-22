@php
    use App\Enums\TransactionDirection;
    use App\Services\GmailService;
    use App\Support\AmountParser;
    use Carbon\CarbonImmutable;
@endphp
{{-- One transaction row plus its inline email and split panels.
     Shared by the date-grouped list and the expanded merchant cluster, so the
     panels behave identically in both modes.

     Passed in: $transaction.
     Read from the component's view data: $account, $formatMoney, $gmailEnabled,
     $emailPanelTxnId, $emailResults, $emailScanError, $splitPanelTxnId,
     $splitLines, $splitCategories, $splitError.

     Requires the host component itself, not just view data: public
     isBulkExcluded(Transaction) and bulkExclusionReason(Transaction), and a
     wire-bindable public array $selected keyed by transaction id, which the
     checkbox column binds as selected.{id}. This partial is therefore specific
     to TransactionList rather than includable by anything supplying the
     variables above. --}}
@php
    $tone = $transaction->direction === TransactionDirection::Credit ? 'inc' : 'out';
    $splitCategoryLabel = $transaction->isSplit()
        ? $transaction->splits->map(fn ($s) => $s->category?->name)->filter()->unique()->join(' · ')
        : null;
    $metaParts = array_filter([
        $splitCategoryLabel !== null && $splitCategoryLabel !== '' ? $splitCategoryLabel : $transaction->category?->name,
        $account === null ? $transaction->account?->name : null,
    ]);
    $isPlanned = $transaction->planned_transaction_id !== null;
    $bulkExcluded = $this->isBulkExcluded($transaction);
    $bulkReason = $this->bulkExclusionReason($transaction);
@endphp
<div wire:key="txn-wrap-{{ $transaction->id }}" class="flex items-start gap-2">
    {{-- Distinct wire:key per branch. The wrapper key is the same either way,
         so without these Livewire would morph one checkbox into the other in
         place — stripping wire:model.live and adding disabled on a live
         ui-checkbox — when saveSplit()/unsplit() below flip eligibility. --}}
    @if($bulkExcluded)
        {{-- The reason is not left to title alone: a disabled control is out of
             the tab order and title never fires on touch, so it is also given
             an accessible name and an sr-only description.

             aria-disabled MUST be bound, not written as a literal. Flux folds a
             static `aria-disabled="true"` into the valueless-attribute form and
             emits aria-disabled="aria-disabled", which is not a valid ARIA
             value, so the state would be silently dropped by assistive tech.
             ui-checkbox is a role=checkbox custom element that never sets it
             itself, so this attribute is the only thing conveying the state. --}}
        <flux:checkbox
            wire:key="select-{{ $transaction->id }}-off"
            disabled
            :aria-disabled="'true'"
            class="mt-5 shrink-0"
            :aria-label="'Cannot select ' . $transaction->description"
            aria-describedby="select-reason-{{ $transaction->id }}"
            :title="$bulkReason"
            data-testid="select-excluded-{{ $transaction->id }}"
        />
        <span id="select-reason-{{ $transaction->id }}" class="sr-only">{{ $bulkReason }}</span>
    @else
        <flux:checkbox
            wire:key="select-{{ $transaction->id }}-on"
            wire:model.live="selected.{{ $transaction->id }}"
            class="mt-5 shrink-0"
            :aria-label="'Select ' . $transaction->description"
            data-testid="select-{{ $transaction->id }}"
        />
    @endif
    <div class="min-w-0 flex-1">
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
                                    @if(is_array($email->details))
                                        <x-cib.receipt-summary :receipt="$email->details"/>
                                    @elseif($email->snippet)
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
                                    @if(is_array($result['details'] ?? null))
                                        <x-cib.receipt-summary :receipt="$result['details']"/>
                                    @elseif($result['snippet'])
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
                            <x-category-combobox
                                wire:model="splitLines.{{ $index }}.category_id"
                                :categories="$splitCategories"
                                placeholder="Category"
                                size="sm"
                            />
                            <flux:input wire:model.live.debounce.400ms="splitLines.{{ $index }}.amount" size="sm" class="split-amount" inputmode="decimal" placeholder="0.00"/>
                            <flux:input wire:model.blur="splitLines.{{ $index }}.notes" size="sm" class="split-notes" placeholder="Note (optional)"/>
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
                        <flux:button variant="primary" size="sm" wire:click="saveSplit" :disabled="$splitRemainderCents !== 0 || count($splitLines) < 2" data-testid="save-split-{{ $transaction->id }}">Save split</flux:button>
                    </div>
                </div>
            </div>
        @endif
    </div>{{-- /min-w-0 flex-1 --}}
</div>{{-- /txn-wrap --}}
