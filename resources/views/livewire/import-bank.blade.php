@php use App\Enums\BankImportStatus; use Illuminate\Support\Str; @endphp
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
                        <flux:select wire:model.live="accountId" data-testid="import-bank-account-select">
                            <option value="">— pick an account —</option>
                            @foreach($accounts as $account)
                                <option
                                    value="{{ $account->id }}"
                                    @disabled(! $account->acceptsCsvImports())
                                >
                                    {{ $account->name }}
                                    @unless($account->acceptsCsvImports())
                                        ({{ $account->import_source->label() }})
                                    @endunless
                                </option>
                            @endforeach
                        </flux:select>
                    @if($this->lastImportedDate !== null)
                        <flux:text size="sm" class="mt-2" data-testid="import-bank-last-imported">
                            Last imported transaction: {{ $this->lastImportedDate->format('d/m/Y') }} — export your next statement from this date.
                        </flux:text>
                    @endif
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
                        <flux:input
                            wire:model="newAccountBalance"
                            label="Current balance"
                            type="number"
                            step="0.01"
                            placeholder="1686.19"
                            data-testid="import-bank-new-balance"
                        />
                        <flux:text size="sm" class="text-fg-3">Optional — the account's balance right now. Leave blank for 0.</flux:text>
                        <flux:select wire:model="newAccountCurrency" label="Currency" data-testid="import-bank-new-currency">
                            <flux:select.option value="">Base currency</flux:select.option>
                            @foreach($currencies as $code)
                                <flux:select.option value="{{ $code }}">{{ $code }}</flux:select.option>
                            @endforeach
                        </flux:select>
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

        @if (config('budget.pdf_import.enabled'))
            <x-cib.card data-testid="import-bank-pdf">
                <flux:heading size="lg">{{ __('Or upload a PDF statement') }}</flux:heading>
                <flux:text size="sm" class="mt-1">
                    {{ __('The PDF is stored in your statement vault and read by an AI provider, so the whole file is sent to it. You review the transactions before anything is added. Up to :size MB; uses the account chosen above if any.', ['size' => intdiv(\App\Livewire\ImportBank::maxPdfKilobytes(), 1024)]) }}
                </flux:text>
                <div class="mt-4 space-y-3">
                    <input type="file" wire:model="pdfFile" accept=".pdf,application/pdf" data-testid="import-bank-pdf-input" class="block w-full text-sm"/>
                    @error('pdfFile') <flux:text class="text-sm text-cib-red-600">{{ $message }}</flux:text> @enderror
                    <flux:button wire:click="startPdfImport" wire:loading.attr="disabled" data-testid="import-bank-pdf-start">{{ __('Read the PDF') }}</flux:button>
                </div>
            </x-cib.card>
        @endif
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

        @if($format !== null)
            <x-cib.card data-testid="import-bank-format">
                <flux:heading size="lg">{{ __('How is this file written?') }}</flux:heading>
                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                    <flux:select wire:model.live="mapping.date_format" :label="__('Date format')" data-testid="import-bank-date-format">
                        @foreach($dateFormatOptions === [] ? array_keys(\App\DTOs\CsvFormat::DATE_FORMATS) : $dateFormatOptions as $option)
                            <option value="{{ $option }}">{{ \App\DTOs\CsvFormat::DATE_FORMATS[$option] ?? $option }}</option>
                        @endforeach
                        @if($dateFormatOptions !== [] && ! in_array($format->dateFormat, $dateFormatOptions, true))
                            <option value="{{ $format->dateFormat }}">{{ \App\DTOs\CsvFormat::DATE_FORMATS[$format->dateFormat] ?? $format->dateFormat }} ({{ __('does not fit this file') }})</option>
                        @endif
                    </flux:select>
                    <flux:select wire:model.live="mapping.number_format" :label="__('Number format')" data-testid="import-bank-number-format">
                        @foreach(\App\Enums\CsvNumberFormat::cases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="mapping.sign_convention" :label="__('Money in and out')" data-testid="import-bank-sign-convention">
                        @foreach(\App\Enums\CsvSignConvention::cases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="mapping.delimiter" :label="__('Delimiter')" data-testid="import-bank-delimiter">
                        <option value=",">{{ __('Comma') }}</option>
                        <option value=";">{{ __('Semicolon') }}</option>
                    </flux:select>
                    <flux:input wire:model.live.debounce.500ms="mapping.skip_rows" type="number" min="0" :label="__('Rows to skip before the headers')" data-testid="import-bank-skip-rows"/>
                    @if($format->signConvention === \App\Enums\CsvSignConvention::TypeColumn)
                        <flux:select wire:model.live="mapping.type" :label="__('Type column')" data-testid="import-bank-type-column">
                            <option value="">— unmapped —</option>
                            @foreach($headers as $header)
                                <option value="{{ $header }}">{{ $header }}</option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model.live.debounce.500ms="mapping.type_inflow_value" :label="__('Value meaning money in')" data-testid="import-bank-inflow-value"/>
                    @endif
                </div>
                @error('mapping.skip_rows') <flux:text class="mt-2 text-sm text-cib-red-600" data-testid="import-bank-format-error">{{ $message }}</flux:text> @enderror
                @error('mapping.date_format') <flux:text class="mt-2 text-sm text-cib-red-600" data-testid="import-bank-format-error">{{ $message }}</flux:text> @enderror
                @error('mapping.type') <flux:text class="mt-2 text-sm text-cib-red-600">{{ $message }}</flux:text> @enderror
            </x-cib.card>
        @endif

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
                </div>
                @if($storedInvalid !== null)
                    @if($storedInvalid > 0)
                        <flux:text size="sm" class="mt-3 text-cib-red-600" data-testid="import-bank-invalid-rows">
                            {{ trans_choice(':count row is invalid and blocks the import.|:count rows are invalid and block the import.', $storedInvalid) }}
                        </flux:text>
                    @endif
                @elseif($summary->errorRows !== [])
                    @if($format !== null)
                        <flux:text size="sm" class="mt-3 text-cib-red-600" data-testid="import-bank-invalid-rows">
                            {{ trans_choice(':count row is invalid and blocks the import.|:count rows are invalid and block the import.', count($summary->errorRows)) }}
                        </flux:text>
                        <ul class="mt-1 list-disc pl-5 text-xs text-cib-red-600">
                            @foreach(array_slice($summary->errorRows, 0, 5) as $errorRow)
                                <li>{{ __('Row :row', ['row' => $errorRow['row']]) }}: {{ $errorRow['error'] }}</li>
                            @endforeach
                        </ul>
                    @else
                        <flux:text size="sm" class="mt-3 text-cib-red-600">
                            {{ count($summary->errorRows) }} rows could not be parsed and will be skipped.
                        </flux:text>
                    @endif
                @endif
            </x-cib.card>
        @endif

        @if($continuity !== null)
            @if($continuity['status'] === 'gap')
                <flux:callout variant="warning" data-testid="import-bank-continuity-gap">
                    <flux:callout.heading>Possible missing records</flux:callout.heading>
                    This file starts {{ $continuity['fileEarliest']->format('d/m/Y') }}, but your last import covered up to {{ $continuity['lastImportedDate']->format('d/m/Y') }}. Transactions between those dates may be missing.
                </flux:callout>
            @else
                <flux:text size="sm" data-testid="import-bank-continuity-ok">
                    Continuous with your last import (covered up to {{ $continuity['lastImportedDate']->format('d/m/Y') }}).
                </flux:text>
            @endif
        @endif

        @if($accountChoice === 'existing')
            <x-cib.card>
                <flux:heading size="lg">Account balance</flux:heading>
                <flux:text size="sm" class="mt-1">
                    The account's balance right now. Pre-filled from the statement's closing balance when available.
                </flux:text>
                <div class="mt-3 max-w-xs">
                    <flux:input
                        wire:model="currentBalance"
                        label="Current balance"
                        type="number"
                        step="0.01"
                        placeholder="1686.19"
                        data-testid="import-bank-current-balance"
                    />
                    @error('currentBalance') <flux:text class="text-cib-red-600 mt-1 text-sm">{{ $message }}</flux:text> @enderror
                </div>
            </x-cib.card>
        @endif

        @if(! empty($previewRows) && $clean === null)
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

        @if($clean !== null)
            <x-cib.card data-testid="import-bank-clean">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <flux:heading size="lg">{{ __('Check your rows') }}</flux:heading>
                    <div class="flex items-center gap-3">
                        <flux:text size="sm" data-testid="import-bank-clean-counts">
                            {{ trans_choice(':count row|:count rows', $clean['total']) }},
                            <span class="{{ $clean['invalid'] > 0 ? 'font-bold text-cib-red-600' : '' }}">{{ trans_choice(':count invalid|:count invalid', $clean['invalid']) }}</span>
                        </flux:text>
                        <flux:checkbox wire:model.live="errorsOnly" :label="__('Errors only')" data-testid="import-bank-errors-only"/>
                        <flux:button size="sm" variant="ghost" wire:click="closeRows">{{ __('Close') }}</flux:button>
                    </div>
                </div>
                @error('rows') <flux:text class="mt-2 text-sm text-cib-red-600">{{ $message }}</flux:text> @enderror

                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs font-bold uppercase">
                                <th class="py-2 pr-3">#</th>
                                <th class="py-2 pr-3">{{ __('Date') }}</th>
                                <th class="py-2 pr-3">{{ __('Description') }}</th>
                                <th class="py-2 pr-3">{{ __('Amount (negative is money out)') }}</th>
                                <th class="py-2 pr-3">{{ __('Category') }}</th>
                                <th class="py-2 pr-3">{{ __('Tags') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($clean['rows'] as $importRow)
                                @php
                                    $cells = [
                                        'date' => $importRow->date?->format($format?->dateFormat ?? 'd/m/Y') ?? $importRow->raw_date,
                                        'description' => $importRow->description,
                                        'amount' => $importRow->amount === null ? $importRow->raw_amount : app(\App\Services\CsvImport\CsvNumberParser::class)->format($importRow->amount, ($format ?? new \App\DTOs\CsvFormat)->numberFormat),
                                        'category_value' => $importRow->category_value,
                                        'tag_value' => $importRow->tag_value,
                                    ];
                                @endphp
                                <tr wire:key="import-row-{{ $importRow->id }}" class="border-t border-cib-black/10" data-testid="import-row-{{ $importRow->id }}" data-valid="{{ $importRow->isValid() ? 'true' : 'false' }}">
                                    <td class="py-2 pr-3">{{ $importRow->row_number }}</td>
                                    @foreach($cells as $field => $cellValue)
                                        <td class="py-1 pr-3">
                                            <input type="text" value="{{ $cellValue }}"
                                                   wire:change="updateRow({{ $importRow->id }}, '{{ $field }}', $event.target.value)"
                                                   data-testid="import-row-{{ $importRow->id }}-{{ $field }}"
                                                   aria-label="{{ __('Row :row :field', ['row' => $importRow->row_number, 'field' => $field]) }}"
                                                   @isset($importRow->errors[$field]) aria-describedby="import-row-{{ $importRow->id }}-{{ $field }}-error" @endisset
                                                   aria-invalid="{{ isset($importRow->errors[$field]) ? 'true' : 'false' }}"
                                                   class="w-full rounded border px-2 py-1 {{ isset($importRow->errors[$field]) ? 'border-cib-red-600 bg-red-50' : 'border-cib-black/20' }}"/>
                                            @isset($importRow->errors[$field])
                                                <div id="import-row-{{ $importRow->id }}-{{ $field }}-error" class="text-xs text-cib-red-600" data-testid="import-row-{{ $importRow->id }}-{{ $field }}-error">{{ $importRow->errors[$field] }}</div>
                                            @endisset
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $clean['rows']->links() }}

                @if($clean['categoryValues'] !== [] || $clean['tagValues'] !== [])
                    <div class="mt-6 space-y-4" data-testid="import-bank-value-mapping">
                        <flux:heading size="md">{{ __('Match the file\'s categories and tags') }}</flux:heading>
                        @error('value_mapping') <flux:text class="text-sm text-cib-red-600">{{ $message }}</flux:text> @enderror

                        @foreach($clean['categoryValues'] as $value)
                            <div class="flex flex-wrap items-center gap-3" wire:key="category-map-{{ \App\Services\CsvImport\BankImportValueMapping::key($value) }}">
                                <span class="w-48 truncate font-bold">{{ $value }}</span>
                                <flux:select wire:change="mapCategory('{{ \App\Services\CsvImport\BankImportValueMapping::key($value) }}', $event.target.value)" aria-label="{{ $value }}" class="max-w-xs" data-testid="map-category-{{ \App\Services\CsvImport\BankImportValueMapping::key($value) }}">
                                    <option value="">{{ __('Leave empty') }}</option>
                                    @foreach($clean['categories'] as $category)
                                        <option value="{{ $category->id }}" @selected(($clean['mapping']['categories'][$value] ?? null) === $category->id)>{{ $category->name }}</option>
                                    @endforeach
                                </flux:select>
                            </div>
                        @endforeach

                        @foreach($clean['tagValues'] as $value)
                            @php($choice = $clean['mapping']['tags'][$value] ?? null)
                            <div class="flex flex-wrap items-center gap-3" wire:key="tag-map-{{ \App\Services\CsvImport\BankImportValueMapping::key($value) }}">
                                <span class="w-48 truncate font-bold">{{ $value }}</span>
                                <flux:select wire:change="mapTag('{{ \App\Services\CsvImport\BankImportValueMapping::key($value) }}', $event.target.value)" aria-label="{{ $value }}" class="max-w-xs" data-testid="map-tag-{{ \App\Services\CsvImport\BankImportValueMapping::key($value) }}">
                                    <option value="">{{ __('Leave empty') }}</option>
                                    <option value="new" @selected(($choice['type'] ?? null) === 'new')>{{ __('Create a new tag') }}</option>
                                    @foreach($clean['tags'] as $tag)
                                        <option value="{{ $tag->id }}" @selected(($choice['type'] ?? null) === 'existing' && ($choice['id'] ?? null) === $tag->id)>{{ $tag->name }}</option>
                                    @endforeach
                                </flux:select>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-cib.card>
        @endif

        <div class="flex items-center gap-3">
            <flux:button wire:click="startOver" variant="ghost" data-testid="import-bank-back">{{ __('Start over') }}</flux:button>
            <flux:button wire:click="reviewRows" variant="ghost" data-testid="import-bank-review-rows">{{ __('Review rows') }}</flux:button>
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
                <x-cib.stat-pill tone="posted" data-testid="import-bank-result-restored">
                    {{ $bankImport->restored_count }} restored
                </x-cib.stat-pill>
                <x-cib.stat-pill tone="planned" data-testid="import-bank-result-skipped">
                    {{ $bankImport->skipped_count }} skipped
                </x-cib.stat-pill>
            </div>

            @if($bankImport->status === BankImportStatus::Failed && $bankImport->error_summary)
                <flux:text class="mt-3 text-cib-red-600">{{ $bankImport->error_summary }}</flux:text>
            @endif

            @if(! empty($bankImport->row_errors))
                <flux:callout variant="warning" class="mt-3" data-testid="import-bank-result-row-errors">
                    <flux:callout.heading>{{ count($bankImport->row_errors) }} row(s) failed</flux:callout.heading>
                    <ul class="mt-2 list-disc pl-5 text-sm">
                        @foreach($bankImport->row_errors as $rowError)
                            <li>
                                Row {{ $rowError['row'] }} —
                                {{ $rowError['description'] ?? '(unknown)' }}:
                                {{ $rowError['message'] }}
                            </li>
                        @endforeach
                    </ul>
                </flux:callout>
            @endif

            @if(! empty($bankImport->unresolved_categories))
                <flux:callout variant="warning" class="mt-3" data-testid="import-bank-result-unresolved-categories">
                    <flux:callout.heading>{{ count($bankImport->unresolved_categories) }} category value(s) did not match one of your categories</flux:callout.heading>
                    <flux:callout.text>{{ __('Those rows were imported uncategorised.') }}</flux:callout.text>
                    <ul class="mt-2 list-disc pl-5 text-sm">
                        @foreach($bankImport->unresolved_categories as $unresolved)
                            <li>{{ $unresolved['value'] }} ({{ $unresolved['rows'] }} {{ Str::plural('row', $unresolved['rows']) }})</li>
                        @endforeach
                    </ul>
                </flux:callout>
            @endif

            @if($bankImport->status->isTerminal())
                <div class="mt-4 flex items-center gap-3">
                    @if($bankImport->status === BankImportStatus::Completed)
                        <flux:button wire:click="revertImport" wire:confirm="{{ __('Remove the transactions this import created or restored?') }}" data-testid="import-bank-revert">
                            {{ __('Revert import') }}
                        </flux:button>
                    @endif
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
