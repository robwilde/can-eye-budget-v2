@php
    use App\Enums\StatementReviewStatus;
    use Illuminate\Support\Number;
@endphp
<div class="space-y-6" data-test="statement-vault">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">{{ __('Statements') }}</flux:heading>
        <x-cib.stat-pill tone="neutral" data-test="statement-storage">{{ __('Storage used: :size', ['size' => $this->storageUsed]) }}</x-cib.stat-pill>
    </div>

    <x-cib.card>
        <flux:heading size="lg">{{ __('Upload statements') }}</flux:heading>
        <flux:text size="sm" class="mt-1">
            {{ __('Add PDF, CSV or XLSX statements: up to 20 files, 25 MB each. They are stored in the vault only; nothing is imported.') }}
        </flux:text>

        <div class="mt-4 space-y-4">
            <div>
                <flux:label>{{ __('Files') }}</flux:label>
                <input type="file" wire:model="uploads" multiple accept=".pdf,.csv,.xlsx" data-test="statement-upload-input" class="mt-1 block w-full text-sm"/>
                @if ($uploads !== [])
                    <div class="mt-2 flex flex-wrap items-center gap-2" data-test="statement-upload-queue">
                        <flux:text size="sm">{{ trans_choice(':count file ready to upload|:count files ready to upload', count($uploads)) }}</flux:text>
                        <flux:button size="xs" variant="ghost" wire:click="clearUploads" data-test="statement-upload-clear">{{ __('Clear') }}</flux:button>
                    </div>
                @endif
                @error('uploads') <flux:text class="mt-1 text-sm text-cib-red-600">{{ $message }}</flux:text> @enderror
                @error('uploads.*') <flux:text class="mt-1 text-sm text-cib-red-600">{{ $message }}</flux:text> @enderror
            </div>

            <flux:field>
                <flux:label>{{ __('File onto') }}</flux:label>
                <flux:select wire:model="uploadAccountId" data-test="statement-upload-account">
                    <flux:select.option value="">{{ __('Inbox (decide later)') }}</flux:select.option>
                    @foreach ($this->accounts as $account)
                        <flux:select.option value="{{ $account->id }}">{{ $account->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="uploadAccountId"/>
            </flux:field>

            <flux:button variant="primary" wire:click="upload" wire:loading.attr="disabled" data-test="statement-upload">{{ __('Upload') }}</flux:button>
        </div>

        @if ($uploadNotice)
            <flux:text class="mt-4 font-bold" data-test="statement-upload-notice">{{ $uploadNotice }}</flux:text>
        @endif

        @if ($errorMessage)
            <flux:text class="mt-4 font-bold text-cib-red-600" data-test="statement-upload-error">{{ $errorMessage }}</flux:text>
        @endif
    </x-cib.card>

    @error('statement') <flux:text class="font-bold text-cib-red-600" data-test="statement-error">{{ $message }}</flux:text> @enderror

    <section class="space-y-3" data-test="statement-inbox">
        <flux:heading size="lg">{{ __('Inbox') }}</flux:heading>

        @if ($this->inbox->isEmpty())
            <flux:text data-test="statement-inbox-empty">{{ __('No statements are waiting to be linked.') }}</flux:text>
        @else
            <div class="day-card">
                @foreach ($this->inbox as $statement)
                    <div wire:key="inbox-{{ $statement->id }}" class="space-y-2 px-4 py-3" data-test="statement-row-{{ $statement->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex min-w-0 items-center gap-2">
                                <a href="{{ route('statements.download', $statement->id) }}" class="truncate font-bold underline decoration-dotted"
                                   data-test="statement-download-{{ $statement->id }}">{{ $statement->original_filename }}</a>
                                <x-cib.stat-pill tone="neutral">{{ strtoupper($statement->file_type->value) }}</x-cib.stat-pill>
                                <x-cib.stat-pill tone="neutral">{{ Number::fileSize($statement->byte_size, 0, 1) }}</x-cib.stat-pill>
                                @if ($statement->review_status === StatementReviewStatus::Rejected)
                                    <x-cib.stat-pill tone="neutral" data-test="statement-rejected-{{ $statement->id }}">{{ __('Rejected') }}</x-cib.stat-pill>
                                @endif
                            </div>
                            <div class="flex items-center gap-1">
                                <flux:button variant="ghost" size="sm" icon="pencil-square" wire:click="startEditing({{ $statement->id }})"
                                             :aria-label="__('Edit details')" data-test="statement-edit-{{ $statement->id }}"/>
                                <flux:button variant="ghost" size="sm" icon="trash" wire:click="delete({{ $statement->id }})"
                                             wire:confirm="{{ __('Delete this statement and its file?') }}"
                                             :aria-label="__('Delete')" data-test="statement-delete-{{ $statement->id }}"/>
                            </div>
                        </div>

                        <flux:text size="sm">
                            @if ($statement->period_start && $statement->period_end)
                                {{ $statement->period_start->format('d/m/Y') }} – {{ $statement->period_end->format('d/m/Y') }}
                            @else
                                {{ __('Period unknown') }}
                            @endif
                        </flux:text>

                        @if ($statement->suggestedAccount)
                            <div class="flex flex-wrap items-center gap-2" data-test="statement-suggestion-{{ $statement->id }}">
                                <flux:text size="sm">
                                    {{ __('Suggested: :account (:confidence%)', ['account' => $statement->suggestedAccount->name, 'confidence' => (int) round(((float) $statement->suggestion_confidence) * 100)]) }}
                                </flux:text>
                                <flux:button size="sm" variant="primary" wire:click="acceptSuggestion({{ $statement->id }})"
                                             data-test="statement-accept-{{ $statement->id }}">{{ __('Accept') }}</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="rejectSuggestion({{ $statement->id }})"
                                             data-test="statement-reject-{{ $statement->id }}">{{ __('Reject') }}</flux:button>
                            </div>
                        @endif

                        <div class="flex flex-wrap items-center gap-2">
                            <flux:select wire:model="linkAccountIds.{{ $statement->id }}" class="max-w-xs" data-test="statement-link-select-{{ $statement->id }}">
                                <flux:select.option value="">{{ __('Link to account…') }}</flux:select.option>
                                @foreach ($this->accounts as $account)
                                    <flux:select.option value="{{ $account->id }}">{{ $account->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:button size="sm" wire:click="linkTo({{ $statement->id }}, $wire.linkAccountIds[{{ $statement->id }}] ?? null)"
                                         data-test="statement-link-{{ $statement->id }}">{{ __('Link') }}</flux:button>
                        </div>
                        @error('link.'.$statement->id) <flux:text class="text-sm text-cib-red-600">{{ $message }}</flux:text> @enderror
                    </div>
                @endforeach
            </div>
            {{ $this->inbox->links() }}
        @endif
    </section>

    <section class="space-y-3" data-test="statement-linked">
        <flux:heading size="lg">{{ __('Linked statements') }}</flux:heading>

        @if ($this->linked->isEmpty())
            <flux:text data-test="statement-linked-empty">{{ __('No statements are linked to an account yet.') }}</flux:text>
        @else
            <div class="day-card">
                @foreach ($this->linked as $statement)
                    <div wire:key="linked-{{ $statement->id }}" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3" data-test="statement-row-{{ $statement->id }}">
                        <div class="min-w-0">
                            <a href="{{ route('statements.download', $statement->id) }}" class="truncate font-bold underline decoration-dotted"
                               data-test="statement-download-{{ $statement->id }}">{{ $statement->original_filename }}</a>
                            <flux:text size="sm">
                                {{ $statement->account?->name }}
                                @if ($statement->period_start && $statement->period_end)
                                    · {{ $statement->period_start->format('d/m/Y') }} – {{ $statement->period_end->format('d/m/Y') }}
                                @endif
                            </flux:text>
                        </div>
                        <div class="flex items-center gap-1">
                            <flux:button variant="ghost" size="sm" wire:click="unlink({{ $statement->id }})"
                                         data-test="statement-unlink-{{ $statement->id }}">{{ __('Unlink') }}</flux:button>
                            <flux:button variant="ghost" size="sm" icon="pencil-square" wire:click="startEditing({{ $statement->id }})"
                                         :aria-label="__('Edit details')" data-test="statement-edit-{{ $statement->id }}"/>
                            <flux:button variant="ghost" size="sm" icon="trash" wire:click="delete({{ $statement->id }})"
                                         wire:confirm="{{ __('Delete this statement and its file?') }}"
                                         :aria-label="__('Delete')" data-test="statement-delete-{{ $statement->id }}"/>
                        </div>
                    </div>
                @endforeach
            </div>
            {{ $this->linked->links() }}
        @endif
    </section>

    @if ($editingStatementId !== null)
        <x-cib.card data-test="statement-details-form">
            <flux:heading size="lg">{{ __('Statement details') }}</flux:heading>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Institution') }}</flux:label>
                    <flux:input wire:model="institutionHint" data-test="statement-institution"/>
                    <flux:error name="institution_hint"/>
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('Account name') }}</flux:label>
                    <flux:input wire:model="accountNameHint" data-test="statement-account-name"/>
                    <flux:error name="account_name_hint"/>
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('Last four digits') }}</flux:label>
                    <flux:input wire:model="lastFourHint" maxlength="4" data-test="statement-last-four"/>
                    <flux:error name="last_four_hint"/>
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('Currency') }}</flux:label>
                    <flux:input wire:model="currency" maxlength="3" data-test="statement-currency"/>
                    <flux:error name="currency"/>
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('Period start') }}</flux:label>
                    <flux:input type="date" wire:model="periodStart" data-test="statement-period-start"/>
                    <flux:error name="period_start"/>
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('Period end') }}</flux:label>
                    <flux:input type="date" wire:model="periodEnd" data-test="statement-period-end"/>
                    <flux:error name="period_end"/>
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('Opening balance') }}</flux:label>
                    <flux:input wire:model="openingBalance" inputmode="decimal" data-test="statement-opening"/>
                    <flux:error name="openingBalance"/>
                    <flux:error name="opening_balance"/>
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('Closing balance') }}</flux:label>
                    <flux:input wire:model="closingBalance" inputmode="decimal" data-test="statement-closing"/>
                    <flux:error name="closingBalance"/>
                    <flux:error name="closing_balance"/>
                </flux:field>
            </div>

            <div class="mt-4 flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="cancelEditing">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="saveDetails" data-test="statement-save">{{ __('Save') }}</flux:button>
            </div>
        </x-cib.card>
    @endif
</div>
