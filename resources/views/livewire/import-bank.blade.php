@php use App\Enums\BankImportStatus; use App\Enums\ImportSource; @endphp
<div class="space-y-6"
     @if($step === 3 && $bankImport && ! $bankImport->status->isTerminal())
         wire:poll.2s="pollStatus"
     @endif
>
    {{-- Step indicator --}}
    <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-fg-3" data-testid="import-bank-step">
        <span class="{{ $step >= 1 ? 'text-cib-black' : '' }}">1. Upload</span>
        <span>›</span>
        <span class="{{ $step >= 2 ? 'text-cib-black' : '' }}">2. Map &amp; preview</span>
        <span>›</span>
        <span class="{{ $step >= 3 ? 'text-cib-black' : '' }}">3. Import</span>
    </div>

    @if($errorMessage)
        <x-cib.card>
            <flux:text class="text-cib-red-600 font-bold">{{ $errorMessage }}</flux:text>
        </x-cib.card>
    @endif

    {{-- STEP 1 --}}
    @if($step === 1)
        <x-cib.card>
            <flux:heading size="lg">Upload a CSV statement</flux:heading>
            <flux:text size="sm" class="mt-1">
                Choose a CSV file exported from your bank, then pick (or create) the account it belongs to.
            </flux:text>

            <div class="mt-4 space-y-4">
                <div>
                    <flux:label>CSV file</flux:label>
                    <input
                        type="file"
                        wire:model="file"
                        accept=".csv,text/csv"
                        data-testid="import-bank-file-input"
                        class="mt-1 block w-full text-sm"
                    />
                    @error('file') <flux:text class="text-cib-red-600 mt-1 text-sm">{{ $message }}</flux:text> @enderror
                </div>

                <div class="space-y-3">
                    <flux:label>Account</flux:label>
                    <flux:radio.group wire:model.live="accountChoice" data-testid="import-bank-account-choice">
                        <flux:radio value="existing" label="Use an existing account"/>
                        <flux:radio value="new" label="Create a new account for this CSV"/>
                    </flux:radio.group>

                    @if($accountChoice === 'existing')
                        <flux:select wire:model="accountId" data-testid="import-bank-account-select">
                            <option value="">— pick an account —</option>
                            @foreach($accounts as $account)
                                <option
                                    value="{{ $account->id }}"
                                    @disabled($account->import_source === ImportSource::Basiq)
                                >
                                    {{ $account->name }}
                                    @if($account->import_source === ImportSource::Basiq)
                                        (Connected via bank)
                                    @endif
                                </option>
                            @endforeach
                        </flux:select>
                    @else
                        <flux:input
                            wire:model="newAccountName"
                            label="Account name"
                            placeholder="e.g. Westpac Choice"
                            data-testid="import-bank-new-name"
                        />
                        <flux:input
                            wire:model="newAccountLast4"
                            label="Last 4 digits"
                            placeholder="4599"
                            maxlength="4"
                            data-testid="import-bank-new-last4"
                        />
                    @endif
                </div>

                <flux:button
                    variant="primary"
                    wire:click="uploadAndDetectHeaders"
                    wire:loading.attr="disabled"
                    data-testid="import-bank-next"
                >
                    <flux:icon.loading wire:loading wire:target="uploadAndDetectHeaders,file" class="size-4"/>
                    {{ __('Detect columns') }}
                </flux:button>
            </div>
        </x-cib.card>
    @endif

    {{-- STEP 2: mapping + preview --}}
    @if($step === 2)
        <x-cib.card>
            <flux:heading size="lg">Map your columns</flux:heading>
            <flux:text size="sm" class="mt-1">
                We've guessed the mapping. Adjust any rows that look wrong, then confirm.
            </flux:text>

            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3" data-testid="import-bank-mapping">
                @foreach($fields as $field => $label)
                    <flux:select
                        wire:model.live="mapping.{{ $field }}"
                        wire:change="refreshPreview"
                        :label="$label"
                        :data-testid="'import-bank-mapping-' . $field"
                    >
                        <option value="">— unmapped —</option>
                        @foreach($headers as $header)
                            <option value="{{ $header }}">{{ $header }}</option>
                        @endforeach
                    </flux:select>
                @endforeach
            </div>
        </x-cib.card>

        @if($summary)
            <x-cib.card>
                <flux:heading size="lg">Preview summary</flux:heading>
                <div class="mt-4 flex flex-wrap gap-3">
                    <x-cib.stat-pill tone="neutral" data-testid="import-bank-summary-rows">
                        {{ $summary->rowCount }} rows
                    </x-cib.stat-pill>
                    @if($summary->dateRange())
                        <x-cib.stat-pill tone="neutral">{{ $summary->dateRange() }}</x-cib.stat-pill>
                    @endif
                    <x-cib.stat-pill tone="posted">−${{ number_format($summary->totalDebits / 100, 2) }} debits</x-cib.stat-pill>
                    <x-cib.stat-pill tone="income">+${{ number_format($summary->totalCredits / 100, 2) }} credits</x-cib.stat-pill>
                    @if($summary->duplicateCount > 0)
                        <x-cib.stat-pill tone="planned">{{ $summary->duplicateCount }} in-source duplicates</x-cib.stat-pill>
                    @endif
                </div>
                @if($summary->errorRows !== [])
                    <flux:text size="sm" class="mt-3 text-cib-red-600">
                        {{ count($summary->errorRows) }} rows could not be parsed and will be skipped.
                    </flux:text>
                @endif
            </x-cib.card>
        @endif

        @if(! empty($previewRows))
            <x-cib.card>
                <flux:heading size="lg">First 10 rows</flux:heading>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-fg-3">
                                <th class="py-2 pr-3">Date</th>
                                <th class="py-2 pr-3">Description</th>
                                <th class="py-2 pr-3 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($previewRows as $row)
                                <tr class="border-t border-cib-black/10">
                                    <td class="py-2 pr-3">{{ $row->postDate->format('d/m/Y') }}</td>
                                    <td class="py-2 pr-3">{{ \Illuminate\Support\Str::limit($row->description, 60) }}</td>
                                    <td class="py-2 pr-3 text-right tabular-nums">
                                        {{ number_format($row->amount / 100, 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-cib.card>
        @endif

        <div class="flex items-center gap-3">
            <flux:button wire:click="startOver" variant="ghost" data-testid="import-bank-back">{{ __('Start over') }}</flux:button>
            <flux:button
                variant="primary"
                wire:click="confirmImport"
                wire:loading.attr="disabled"
                data-testid="import-bank-confirm"
            >
                <flux:icon.loading wire:loading wire:target="confirmImport" class="size-4"/>
                {{ __('Confirm and import') }}
            </flux:button>
        </div>
    @endif

    {{-- STEP 3: result + polling --}}
    @if($step === 3 && $bankImport)
        <x-cib.card data-testid="import-bank-result">
            <flux:heading size="lg">
                @switch($bankImport->status)
                    @case(BankImportStatus::Completed) Import complete @break
                    @case(BankImportStatus::Failed) Import failed @break
                    @default Import running… @break
                @endswitch
            </flux:heading>

            <div class="mt-3 flex flex-wrap gap-3">
                <x-cib.stat-pill tone="neutral" data-testid="import-bank-result-status">
                    {{ $bankImport->status->label() }}
                </x-cib.stat-pill>
                <x-cib.stat-pill tone="income" data-testid="import-bank-result-imported">
                    {{ $bankImport->imported_count }} imported
                </x-cib.stat-pill>
                <x-cib.stat-pill tone="planned" data-testid="import-bank-result-skipped">
                    {{ $bankImport->skipped_count }} skipped (duplicates)
                </x-cib.stat-pill>
            </div>

            @if($bankImport->status === BankImportStatus::Failed && $bankImport->error_summary)
                <flux:text class="mt-3 text-cib-red-600">{{ $bankImport->error_summary }}</flux:text>
            @endif

            @if($bankImport->status->isTerminal())
                <div class="mt-4 flex items-center gap-3">
                    <flux:button wire:click="startOver" variant="primary" data-testid="import-bank-import-another">
                        {{ __('Import another') }}
                    </flux:button>
                    <flux:button :href="route('transactions')" variant="ghost">
                        {{ __('View transactions') }}
                    </flux:button>
                </div>
            @endif
        </x-cib.card>
    @endif
</div>
