@php
    use App\Services\MerchantBrands\ContextDevCreditBudget;
    use Carbon\CarbonImmutable;
    use Illuminate\Support\Str;
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

        {{-- Clustering only pays off while triaging uncategorised rows, so the
             toggle is offered exactly there rather than adding a permanent
             control that is usually the wrong choice. --}}
        @if($categorised === 'uncategorised')
            <x-cib.filter-toggle
                :options="[
                    ['value' => 'date', 'label' => 'By date'],
                    ['value' => 'merchant', 'label' => 'By merchant'],
                ]"
                :selected="$groupMode"
                wire-model="groupMode"
            />
        @endif

        {{-- Provenance filter. Only meaningful once rows have categories, and
             'Set by a rule' is the review queue for everything automation
             decided. --}}
        @if($categorised !== 'uncategorised')
            <x-cib.filter-toggle
                :options="[
                    ['value' => 'all', 'label' => 'Any source'],
                    ['value' => 'manual', 'label' => 'Set by you'],
                    ['value' => 'rule', 'label' => 'Set by a rule'],
                ]"
                :selected="$source"
                wire-model="source"
            />
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search transactions..." icon="magnifying-glass" size="sm" class="min-w-48 flex-1"/>

        @if($creditSummary !== null)
            <flux:badge size="sm" class="shrink-0" data-testid="context-dev-credits">
                @if($creditSummary['balance'] === null)
                    Credits: unknown
                @else
                    {{ number_format($creditSummary['balance']) }} credits · {{ number_format($creditSummary['leftToday']) }} left today
                @endif
            </flux:badge>
        @endif
    </div>

    @if($inMerchantMode ? $clusters->isEmpty() : $transactions->isEmpty())
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
        @php
            // Three-state: none / some / all of the eligible rows on screen.
            //
            // $selected is authoritative here even while a scope is held: the
            // component mirrors the scope, minus anything the user has
            // subtracted from it, into exactly the rows this page renders. A
            // scope covering rows off screen therefore never inflates this
            // count, and rows the user has taken back out of it read as
            // unticked in both the boxes and the label.
            $eligibleOnScreen = count($pageEligibleIds);
            $selectedOnScreen = collect($pageEligibleIds)->filter(fn (int $id): bool => ! empty($selected[$id]))->count();
            $allOnScreenSelected = $eligibleOnScreen > 0 && $selectedOnScreen === $eligibleOnScreen;
        @endphp

        <div class="flex flex-wrap items-center gap-3 px-1">
            @if($eligibleOnScreen > 0)
                {{-- No aria-pressed. The WAI-ARIA APG button pattern requires a
                     toggle's name to stay fixed while its state changes; this
                     label deliberately does the opposite, naming the action and
                     stating the partial count. Carrying both would announce the
                     selection twice, in different words, and pair an action name
                     with a contradictory state. The label reaches sighted and
                     screen-reader users alike, and is more specific than
                     none/mixed/all. --}}
                <flux:button size="sm" variant="ghost"
                             wire:click="toggleVisible(@js($pageEligibleIds), @js(! $allOnScreenSelected))"
                             data-testid="select-visible">
                    @if($allOnScreenSelected)
                        Clear these {{ $eligibleOnScreen }}
                    @elseif($selectedOnScreen > 0)
                        Select the other {{ $eligibleOnScreen - $selectedOnScreen }} ({{ $selectedOnScreen }} of {{ $eligibleOnScreen }} selected)
                    @else
                        {{ $inMerchantMode ? 'Select these ' . $eligibleOnScreen : 'Select this page (' . $eligibleOnScreen . ')' }}
                    @endif
                </flux:button>
            @endif

            {{-- Deliberately a separate control from the on-screen checkbox, and
                 it states the real number, so "all" can never be mistaken for
                 "this page". --}}
            @if($bulkScope === null && $matchingCount > $eligibleOnScreen)
                <flux:button size="sm" variant="ghost" wire:click="selectAllMatching" data-testid="select-all-matching">
                    Select all {{ $matchingCount }} matching this filter
                </flux:button>
            @endif

            @if($pendingMerchantKeys !== [])
                <flux:button size="sm" variant="ghost" disabled data-testid="update-visible-merchants">
                    Identifying {{ count($pendingMerchantKeys) }}…
                </flux:button>
            @elseif($identifiableKeys !== [])
                <flux:button size="sm" variant="ghost" wire:click="updateVisibleMerchants" data-testid="update-visible-merchants">
                    Update {{ count($identifiableKeys) }} {{ Str::plural('merchant', count($identifiableKeys)) }} on this page (≤{{ count($identifiableKeys) * ContextDevCreditBudget::BRAND_LOOKUP_CREDITS }} credits)
                </flux:button>
            @endif
        </div>
        <div class="relative" @if($pendingMerchantKeys !== []) wire:poll.4s="pollMerchantBrands" @endif>
            <div wire:loading class="absolute inset-0 z-10 flex items-center justify-center bg-white/60 dark:bg-zinc-900/60">
                <flux:icon.arrow-path class="size-6 animate-spin text-zinc-400"/>
            </div>

            @if($inMerchantMode)
                <div class="flex items-center gap-4 px-4 py-2">
                    <span class="cib-label flex min-w-0 flex-1 items-center gap-1">Merchant</span>
                    <span class="cib-label w-32">Spread</span>
                    <span class="cib-label w-24">Dates</span>
                    <span class="cib-label w-28 text-right">Total</span>
                </div>

                <div class="agenda" data-testid="merchant-clusters">
                    @foreach($clusters as $cluster)
                        @php
                            $isExpanded = $expandedKey === $cluster->merchant_key;
                            $spanStart = CarbonImmutable::parse($cluster->first_date);
                            $spanEnd = CarbonImmutable::parse($cluster->last_date);
                            // A cluster is "mixed" when its rows disagree about
                            // what they are: different accounts, both directions,
                            // or an amount range wide enough that one category
                            // almost certainly does not fit all of them.
                            $isMixedDirection = (int) $cluster->direction_count > 1;
                            $isMultiAccount = (int) $cluster->account_count > 1;
                            $minAmount = (int) $cluster->min_amount;
                            $maxAmount = (int) $cluster->max_amount;
                            // A zero amount is a real row — the CSV parser emits
                            // one for an empty credit column — and the ratio test
                            // divides it away: min 0 against max $900 is the
                            // widest possible range, so it is treated as one.
                            $isWideSpread = $minAmount === 0
                                ? $maxAmount > 0
                                : $maxAmount >= $minAmount * 5;
                            // Named from content, the button would read as a run
                            // of four unlabelled numbers, and the badge meanings
                            // and the wide-spread colour would never reach it.
                            $clusterLabel = implode(', ', array_filter([
                                $cluster->merchant_key,
                                $cluster->row_count.' '.Str::plural('transaction', (int) $cluster->row_count),
                                'total '.$formatMoney((int) $cluster->total_amount),
                                'amounts '.$formatMoney($minAmount).' to '.$formatMoney($maxAmount),
                                $isWideSpread ? 'wide range' : null,
                                $isMixedDirection ? 'money in and money out' : null,
                                $isMultiAccount ? $cluster->account_count.' accounts' : null,
                                $spanStart->format('j M').' to '.$spanEnd->format('j M'),
                            ]));
                        @endphp
                        <section wire:key="cluster-{{ md5($cluster->merchant_key) }}" class="agenda-group">
                            <button type="button"
                                    wire:click="toggleCluster(@js($cluster->merchant_key))"
                                    class="flex w-full items-center gap-4 px-4 py-3 text-left"
                                    data-testid="cluster-{{ md5($cluster->merchant_key) }}"
                                    aria-controls="cluster-rows-{{ md5($cluster->merchant_key) }}"
                                    aria-expanded="{{ $isExpanded ? 'true' : 'false' }}"
                                    aria-label="{{ $clusterLabel }}">
                                <span class="flex min-w-0 flex-1 items-center gap-2">
                                    <flux:icon :name="$isExpanded ? 'chevron-down' : 'chevron-right'" class="size-4 shrink-0 text-zinc-400"/>
                                    <span class="min-w-0 truncate font-medium">{{ $cluster->merchant_key }}</span>
                                    {{-- .pill carries no styles of its own; app.css
                                         scopes it per container. cluster-head is this
                                         header's scope, so the badges do not inherit
                                         .tx-meta's row typography and margin. --}}
                                    <span class="cluster-head shrink-0">
                                        <span class="pill">{{ $cluster->row_count }}</span>
                                        @if($isMixedDirection)
                                            <span class="pill split">in + out</span>
                                        @endif
                                        @if($isMultiAccount)
                                            <span class="pill split">{{ $cluster->account_count }} accounts</span>
                                        @endif
                                    </span>
                                </span>
                                <span @class(['w-32 text-sm', 'font-semibold text-cib-yellow-600' => $isWideSpread])>
                                    @if($isWideSpread)
                                        <flux:icon.exclamation-triangle class="mr-1 inline size-3.5 align-text-bottom"/>
                                    @endif
                                    {{ $formatMoney($minAmount) }}–{{ $formatMoney($maxAmount) }}
                                </span>
                                <span class="w-24 text-sm text-zinc-500">
                                    {{ $spanStart->format('j M') }}@if(! $spanStart->isSameDay($spanEnd))–{{ $spanEnd->format('j M') }}@endif
                                </span>
                                <span class="w-28 text-right font-medium">{{ $formatMoney((int) $cluster->total_amount) }}</span>
                            </button>

                            {{-- Selecting a whole cluster is the point of this
                                 view: one click stands in for however many rows
                                 the cluster holds, stored as a scope rather
                                 than an id list.

                                 Labelled from eligible_count, not row_count:
                                 transfers cluster here but a bulk write refuses
                                 them, so row_count would promise rows the click
                                 cannot touch. A cluster of pure transfers
                                 offers no control at all rather than a no-op
                                 one. --}}
                            @if((int) $cluster->eligible_count > 0)
                                <div class="px-4 pb-2">
                                    <flux:button size="sm" variant="ghost"
                                                 wire:click="selectCluster(@js($cluster->merchant_key))"
                                                 data-testid="select-cluster-{{ md5($cluster->merchant_key) }}">
                                        Select all {{ (int) $cluster->eligible_count }} in this merchant
                                    </flux:button>
                                </div>
                            @endif

                            @if($isExpanded)
                                <div id="cluster-rows-{{ md5($cluster->merchant_key) }}" class="day-card" data-testid="cluster-rows">
                                    @foreach($clusterRows as $transaction)
                                        @include('livewire.partials.transaction-row', ['transaction' => $transaction])
                                    @endforeach
                                </div>
                            @endif
                        </section>
                    @endforeach
                </div>
            @else
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
                                    @include('livewire.partials.transaction-row', ['transaction' => $transaction])
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>
            @endif
        </div>

        <x-cib.card class="mt-4">
            <div class="flex items-center justify-between gap-4">
                {{ $inMerchantMode ? $clusters->links() : $transactions->links() }}
            </div>
        </x-cib.card>
    @endif

    {{-- Sticky bulk bar and notice sit OUTSIDE the empty-state conditional.
         Finishing the last uncategorised rows is the one path guaranteed to
         empty the list, and that is exactly when the confirmation matters; the
         bar also carries the only Clear control, which must stay reachable if a
         filter change empties the list while a scope is held. --}}
    @if($selectionCount > 0)
        <div class="sticky bottom-4 z-20 mt-4" data-testid="bulk-bar">
            <x-cib.card class="border-2 border-amber-400 shadow-lg dark:border-amber-500">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="font-medium" data-testid="bulk-count">
                        {{ $selectionCount }} selected
                    </span>

                    <div class="min-w-56">
                        <x-category-combobox
                            wire:model="bulkCategoryId"
                            :categories="$splitCategories"
                            placeholder="Category"
                            size="sm"
                        />
                    </div>

                    <flux:button variant="primary" size="sm"
                                 wire:click="applyCategoryToSelection"
                                 wire:loading.attr="disabled" wire:target="applyCategoryToSelection"
                                 data-testid="bulk-apply">
                        Apply to these {{ $selectionCount }}
                    </flux:button>

                    {{-- Shown while reviewing machine-set categories, which
                         is the only context where reverting is the action
                         you want. --}}
                    @if($source === 'rule')
                        <flux:button variant="ghost" size="sm"
                                     wire:click="revertMachineCategories"
                                     wire:loading.attr="disabled" wire:target="revertMachineCategories"
                                     data-testid="bulk-revert">
                            Undo rule-set categories
                        </flux:button>
                    @endif

                    <flux:button variant="ghost" size="sm" wire:click="clearSelection" data-testid="bulk-clear">
                        Clear
                    </flux:button>

                    {{-- A second, separate action. "Apply to these N" is
                         bounded; this one creates an auto-apply rule that
                         sweeps all history and every future import, so it
                         gets its own button and its own confirmation. --}}
                    <flux:button variant="ghost" size="sm"
                                 wire:click="openRulePanel"
                                 data-testid="rule-open">
                        Create rule…
                    </flux:button>

                    @if($bulkError)
                        <span class="text-sm text-red-600 dark:text-red-400" data-testid="bulk-error">{{ $bulkError }}</span>
                    @endif
                </div>

                {{-- Pre-commit preview. Numbers come from a dry-run of the
                     real RuleEvaluator against the same trigger the commit
                     path builds, so what is shown is what will happen. --}}
                @if($rulePanelOpen)
                    <div class="mt-3 border-t border-zinc-200 pt-3 dark:border-zinc-700" data-testid="rule-panel">
                        <div class="flex flex-wrap items-end gap-3">
                            <div class="min-w-64">
                                <flux:input
                                    wire:model.live.debounce.500ms="ruleMatchValue"
                                    label="Rule matches descriptions containing"
                                    size="sm"
                                    data-testid="rule-match-value"
                                />
                            </div>

                            <flux:button variant="danger" size="sm"
                                         wire:click="createRuleFromSelection"
                                         wire:loading.attr="disabled" wire:dirty.attr="disabled"
                                         wire:target="createRuleFromSelection,ruleMatchValue,bulkCategoryId"
                                         data-testid="rule-confirm">
                                Create rule + apply to past and future
                            </flux:button>

                            <flux:button variant="ghost" size="sm" wire:click="closeRulePanel" data-testid="rule-cancel">
                                Cancel
                            </flux:button>
                        </div>

                        @if($rulePreview)
                            <div class="mt-3 space-y-1 text-sm" data-testid="rule-preview">
                                <div>
                                    Matches <strong data-testid="rule-preview-total">{{ $rulePreview->totalMatches() }}</strong>
                                    transaction{{ $rulePreview->totalMatches() === 1 ? '' : 's' }},
                                    of which <strong data-testid="rule-preview-beyond">{{ $rulePreview->beyondSelection }}</strong>
                                    {{ $rulePreview->beyondSelection === 1 ? 'is' : 'are' }} outside your selection.
                                </div>
                                <div>
                                    <strong data-testid="rule-preview-change">{{ $rulePreview->wouldChange }}</strong>
                                    will be categorised.
                                </div>
                                @if($rulePreview->protectedByManual > 0)
                                    <div class="text-emerald-700 dark:text-emerald-400" data-testid="rule-preview-protected">
                                        {{ $rulePreview->protectedByManual }} will be left alone because you set their category yourself.
                                    </div>
                                @endif
                                @if($rulePreview->existingCategories !== [])
                                    <div class="text-amber-700 dark:text-amber-500" data-testid="rule-preview-existing">
                                        Outside your selection these already have categories:
                                        @foreach($rulePreview->existingCategories as $name => $count)
                                            {{ $name }} ({{ $count }}){{ ! $loop->last ? ', ' : '' }}
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @else
                            <p class="mt-3 text-sm text-zinc-500" data-testid="rule-preview-empty">
                                Choose a category and a match value to see what this rule would do.
                            </p>
                        @endif
                    </div>
                @endif
            </x-cib.card>
        </div>
    @endif

    @if($bulkNotice)
        <div class="mt-3 text-sm text-emerald-700 dark:text-emerald-400" data-testid="bulk-notice">{{ $bulkNotice }}</div>
    @endif
</div>
