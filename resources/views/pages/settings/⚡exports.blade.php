<?php

use App\Enums\DataExportStatus;
use App\Jobs\GenerateDataExportJob;
use App\Livewire\Attributes\NotAudited;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Exports')] class extends Component {
    #[Locked]
    public ?int $selectedExportId = null;

    /** @return Collection<int, DataExport> */
    #[Computed]
    public function exports(): Collection
    {
        return DataExport::query()->where('user_id', Auth::id())->latest('id')->limit(20)->get();
    }

    #[Computed]
    public function inProgress(): bool
    {
        return $this->exports->contains(fn (DataExport $export): bool => in_array($export->status, [DataExportStatus::Pending, DataExportStatus::Processing], true));
    }

    #[NotAudited]
    public function pollStatus(): void
    {
        unset($this->exports, $this->inProgress);
    }

    public function startExport(): void
    {
        $this->resetErrorBag();

        $export = DB::transaction(function (): ?DataExport {
            User::query()->whereKey(Auth::id())->lockForUpdate()->firstOrFail();

            $busy = DataExport::query()
                ->where('user_id', Auth::id())
                ->whereIn('status', [DataExportStatus::Pending, DataExportStatus::Processing])
                ->exists();

            return $busy ? null : DataExport::query()->create(['user_id' => Auth::id(), 'status' => DataExportStatus::Pending]);
        });

        if ($export === null) {
            $this->addError('export', __('An export is already in progress.'));

            return;
        }

        GenerateDataExportJob::dispatch($export);

        unset($this->exports, $this->inProgress);
    }

    public function selectExport(int $exportId): void
    {
        $this->selectedExportId = $this->exports->firstWhere('id', $exportId)?->id;
    }

    public function deleteExport(): void
    {
        $export = $this->selectedExport();

        if ($export === null || in_array($export->status, [DataExportStatus::Pending, DataExportStatus::Processing], true)) {
            return;
        }

        $export->delete();

        $this->selectedExportId = null;
        unset($this->exports, $this->inProgress);
        $this->modal('confirm-export-delete')->close();
    }

    public function markFailed(): void
    {
        $this->resetErrorBag();
        $export = $this->selectedExport();

        if ($export === null) {
            return;
        }

        if (! $this->idleForAnHour($export)) {
            $this->addError('export', __('Only an export that has been waiting or processing for over an hour can be marked failed.'));

            return;
        }

        $export->update(['status' => DataExportStatus::Failed, 'failure_message' => 'Marked failed by the user.', 'completed_at' => now()]);

        $this->selectedExportId = null;
        unset($this->exports, $this->inProgress);
    }

    public function idleForAnHour(DataExport $export): bool
    {
        return match ($export->status) {
            DataExportStatus::Processing => $export->started_at !== null && $export->started_at->lessThan(now()->subHour()),
            DataExportStatus::Pending => $export->created_at->lessThan(now()->subHour()),
            default => false,
        };
    }

    private function selectedExport(): ?DataExport
    {
        return DataExport::query()->where('user_id', Auth::id())->find($this->selectedExportId);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Exports') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Exports')" :subheading="__('Download a copy of your data')">
        <div @if($this->inProgress) wire:poll.3s="pollStatus" @endif class="my-6 w-full space-y-6">
            <flux:text size="sm">
                {{ __('An export is a zip with a CSV for your accounts, transactions, categories, merchants, merchant aliases, payees, rules, tags and the tags on your transactions, transfer rules, goals (with their accounts, pledges and spends), budgets and budget plans, planned transactions and their occurrences, BNPL orders and their events, and your statement records, one file with those same records and a list of your attachments and documents. It does not contain the files themselves, including statements, or any password, key or email text.') }}
            </flux:text>

            <div>
                <flux:button variant="primary" wire:click="startExport" :disabled="$this->inProgress" data-test="start-export-button">
                    {{ __('Start export') }}
                </flux:button>
                @error('export') <flux:text size="sm" class="mt-2 text-red-600" data-test="export-error">{{ $message }}</flux:text> @enderror
            </div>

            @foreach ($this->exports as $export)
                <div class="flex flex-wrap items-center gap-3" wire:key="export-{{ $export->id }}" data-test="export-row">
                    <flux:badge size="sm" :color="match ($export->status) {
                        App\Enums\DataExportStatus::Completed => 'green',
                        App\Enums\DataExportStatus::Failed => 'red',
                        default => 'zinc',
                    }">{{ $export->status->label() }}</flux:badge>
                    <flux:text size="sm">{{ $export->created_at->diffForHumans() }}</flux:text>

                    @if ($export->status === App\Enums\DataExportStatus::Completed)
                        <flux:text size="sm">{{ Illuminate\Support\Number::fileSize((int) $export->byte_size) }}</flux:text>
                        <flux:button size="sm" :href="route('exports.download', $export->id)" data-test="download-export-button">{{ __('Download') }}</flux:button>
                    @endif

                    @if ($this->idleForAnHour($export))
                        <flux:button size="sm" variant="subtle" wire:click="selectExport({{ $export->id }})" data-test="select-export-button">{{ __('Manage') }}</flux:button>
                    @endif

                    @if (in_array($export->status, [App\Enums\DataExportStatus::Completed, App\Enums\DataExportStatus::Failed], true))
                        <flux:modal.trigger name="confirm-export-delete">
                            <flux:button size="sm" variant="subtle" wire:click="selectExport({{ $export->id }})" data-test="delete-export-button">{{ __('Delete') }}</flux:button>
                        </flux:modal.trigger>
                    @endif

                    @if ($this->selectedExportId === $export->id && $this->idleForAnHour($export))
                        <flux:button size="sm" variant="danger" wire:click="markFailed" data-test="mark-export-failed-button">{{ __('Mark as failed') }}</flux:button>
                    @endif

                    @if ($export->failure_message)
                        <flux:text size="sm" class="text-red-600">{{ $export->failure_message }}</flux:text>
                    @endif
                </div>
            @endforeach
        </div>

        <flux:modal name="confirm-export-delete" class="md:w-96">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete this export?') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('The archive file is deleted. You can start a new export at any time.') }}</flux:text>
                </div>

                <div class="flex gap-2">
                    <flux:spacer />
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button wire:click="deleteExport" variant="danger" data-test="confirm-delete-export-button">{{ __('Delete') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    </x-pages::settings.layout>
</section>
