@php
    use App\Enums\StatementCoverageStatus;

    $tones = [
        StatementCoverageStatus::NotExpected->value => 'bg-bg-page text-fg-3',
        StatementCoverageStatus::Missing->value => 'bg-money-owed-soft text-money-owed-strong',
        StatementCoverageStatus::Covered->value => 'bg-money-available-soft text-cib-black',
        StatementCoverageStatus::Duplicate->value => 'bg-cib-yellow-400 text-cib-black',
        StatementCoverageStatus::Ambiguous->value => 'bg-cib-yellow-400 text-cib-black',
        StatementCoverageStatus::Mismatched->value => 'bg-money-owed-soft text-money-owed-strong',
    ];
@endphp
<div class="space-y-6" data-test="statement-coverage">
    <x-cib.card>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="lg">{{ __('Statement coverage') }}</flux:heading>
            <flux:select wire:model.live="year" class="max-w-32" data-test="coverage-year">
                @foreach ($this->years as $option)
                    <flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="mt-4 grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6" data-test="coverage-grid">
            @foreach ($this->months as $month)
                <div wire:key="coverage-{{ $month->month->format('Y-m') }}"
                     class="rounded-md border-2 border-cib-black p-3 {{ $tones[$month->status->value] }}"
                     data-test="coverage-{{ $month->month->format('Y-m') }}" data-status="{{ $month->status->value }}">
                    <div class="text-xs font-bold uppercase tracking-wide">{{ $month->month->translatedFormat('M') }}</div>
                    <div class="font-bold">{{ $month->status->label() }}</div>
                </div>
            @endforeach
        </div>
    </x-cib.card>

    <section class="agenda-group space-y-3" data-test="coverage-statements">
        <flux:heading size="lg">{{ __('Linked statements') }}</flux:heading>

        @if ($this->statements->isEmpty())
            <flux:text data-test="coverage-statements-empty">{{ __('No statements are linked to this account yet.') }}</flux:text>
        @else
            <div class="day-card">
                @foreach ($this->statements as $statement)
                    <div wire:key="coverage-statement-{{ $statement->id }}" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                        <a href="{{ route('statements.download', $statement->id) }}" class="truncate font-bold underline decoration-dotted">{{ $statement->original_filename }}</a>
                        <flux:text size="sm">
                            @if ($statement->period_start && $statement->period_end)
                                {{ $statement->period_start->format('d/m/Y') }} – {{ $statement->period_end->format('d/m/Y') }}
                            @else
                                {{ __('Period unknown') }}
                            @endif
                        </flux:text>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>
