@php
    use App\Enums\PdfImportStatus;
    use App\Casts\MoneyCast;

    $import = $this->import;
    $plan = $this->plan;
    $published = $import->status === PdfImportStatus::Published;
    $stuck = in_array($import->status, [PdfImportStatus::Pending, PdfImportStatus::Processing], true);
@endphp
<div class="space-y-6" data-test="pdf-import-review"
     @if ($stuck) wire:poll.3s="pollStatus" @endif>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ __('Review PDF import') }}</flux:heading>
            <flux:text size="sm">{{ $import->statement?->original_filename }}</flux:text>
        </div>
        <x-cib.stat-pill tone="neutral" data-test="pdf-import-status">{{ ucfirst($import->status->value) }}</x-cib.stat-pill>
    </div>

    @error('import') <flux:text class="font-bold text-cib-red-600" data-test="pdf-import-error">{{ $message }}</flux:text> @enderror

    @if ($import->status === PdfImportStatus::Failed)
        <x-cib.card><flux:text class="font-bold text-cib-red-600" data-test="pdf-import-failure">{{ $import->failure_message }}</flux:text></x-cib.card>
    @endif

    @if ($stuck)
        <x-cib.card>
            <flux:text data-test="pdf-import-working">{{ __('The statement is being read. This page updates by itself.') }}</flux:text>
            <flux:button class="mt-3" size="sm" variant="ghost" wire:click="markFailed" data-test="pdf-import-mark-failed">{{ __('Mark as failed') }}</flux:button>
            <flux:text size="sm" class="mt-1">{{ __('Only possible once the import has been idle for over an hour.') }}</flux:text>
        </x-cib.card>
    @endif

    @if (in_array($import->status, [PdfImportStatus::Ready, PdfImportStatus::Published, PdfImportStatus::Reverted], true))
        <x-cib.card data-test="pdf-import-summary">
            <flux:heading size="lg">{{ __('Summary') }}</flux:heading>
            <flux:text size="sm">{{ __('Detected: :type', ['type' => str_replace('_', ' ', $import->document_type?->value ?? 'unknown')]) }}</flux:text>
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div><div class="text-xs font-bold uppercase">{{ __('Found') }}</div><div class="font-display text-2xl font-black" data-test="count-found">{{ $this->counts['found'] }}</div></div>
                <div><div class="text-xs font-bold uppercase">{{ __('Already recorded') }}</div><div class="font-display text-2xl font-black" data-test="count-recorded">{{ $this->counts['recorded'] }}</div></div>
                <div><div class="text-xs font-bold uppercase">{{ $published ? __('Imported') : __('To import') }}</div><div class="font-display text-2xl font-black" data-test="count-imported">{{ $this->counts['imported'] }}</div></div>
                <div><div class="text-xs font-bold uppercase">{{ __('Waiting') }}</div><div class="font-display text-2xl font-black" data-test="count-waiting">{{ $this->counts['waiting'] }}</div></div>
            </div>
        </x-cib.card>

        <x-cib.card>
            <div class="flex flex-wrap items-end gap-3">
                <flux:field class="min-w-64">
                    <flux:label>{{ __('Account') }}</flux:label>
                    <flux:select wire:model="accountId" :disabled="$published" data-test="pdf-import-account">
                        <flux:select.option value="">{{ __('Choose an account…') }}</flux:select.option>
                        @foreach ($this->accounts as $account)
                            <flux:select.option value="{{ $account->id }}">{{ $account->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="accountId"/>
                </flux:field>

                @if ($published)
                    <flux:button wire:click="revert" wire:confirm="{{ __('Remove the transactions this import created?') }}" data-test="pdf-import-revert">{{ __('Revert') }}</flux:button>
                @else
                    <flux:button variant="ghost" wire:click="changeAccount" data-test="pdf-import-change-account">{{ __('Use this account') }}</flux:button>
                    <flux:button variant="primary" wire:click="publish" data-test="pdf-import-publish">{{ __('Publish') }}</flux:button>
                @endif
            </div>
        </x-cib.card>

        <section class="agenda-group space-y-3" data-test="pdf-import-lines">
            <flux:heading size="lg">{{ __('Lines') }}</flux:heading>
            <div class="day-card">
                @foreach ($this->lines as $line)
                    @php
                        $state = match (true) {
                            $line->date_unreadable || $line->line_date === null => 'waiting',
                            $published => 'recorded',
                            $plan !== null && isset($plan->matched[$line->id]) => 'matched',
                            $plan !== null && in_array($line->id, $plan->statementOnly, true) => 'new',
                            default => 'unknown',
                        };
                    @endphp
                    <div wire:key="pdf-line-{{ $line->id }}" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3" data-test="pdf-line-{{ $line->id }}" data-state="{{ $state }}">
                        <div class="min-w-0">
                            <div class="truncate font-bold">{{ $line->description }}</div>
                            <flux:text size="sm">
                                @if ($line->date_unreadable || $line->line_date === null)
                                    <span class="font-bold text-cib-red-600" data-test="pdf-line-flag-{{ $line->id }}">{{ __('Date unreadable') }}: {{ $line->raw_date }}</span>
                                @else
                                    {{ $line->line_date->format('d/m/Y') }}
                                @endif
                            </flux:text>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-cib.stat-pill tone="neutral">{{ match ($state) { 'matched' => __('Already recorded'), 'new' => __('To import'), 'recorded' => __('Recorded'), 'waiting' => __('Waiting'), default => '' } }}</x-cib.stat-pill>
                            <span class="font-bold">{{ MoneyCast::format($line->amount) }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
