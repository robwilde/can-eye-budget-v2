<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\StatementLineKind;
use App\Exceptions\Statement\StatementFileUnreadable;
use App\Exceptions\Statement\StatementLineAlreadyFolded;
use App\Exceptions\Statement\StatementLineNotResolvable;
use App\Exceptions\Statement\StatementReconciliationClosed;
use App\Exceptions\Statement\StatementReconciliationIncomplete;
use App\Models\Account;
use App\Models\StatementReconciliation;
use App\Models\StatementReconciliationLine;
use App\Services\CsvImport\CsvColumnMapper;
use App\Services\CsvImport\CsvParserService;
use App\Services\Statement\StatementReconciler;
use App\Support\Redbark\InitialSyncWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * The monthly loop for a Redbark-linked account: upload the bank's statement CSV for a
 * month, review what the feed matched, resolve strays, tick every line, close the month.
 *
 * @property-read StatementReconciliation|null $reconciliation
 * @property-read Collection<int, StatementReconciliationLine> $lines
 * @property-read array<string, string> $monthOptions
 */
#[Layout('layouts::app')]
final class ReconcileStatement extends Component
{
    use WithFileUploads;

    private const string MONTH_FORMAT = 'Y-m';

    #[Locked]
    public Account $account;

    #[Url]
    public string $month = '';

    public ?TemporaryUploadedFile $file = null;

    /** True while replacing the file of an existing open reconciliation. */
    public bool $uploading = false;

    /** @var list<string> */
    public array $headers = [];

    /** @var array<string, string|null> */
    public array $mapping = [];

    #[Locked]
    public ?string $storedPath = null;

    #[Locked]
    public ?string $originalFilename = null;

    public ?string $errorMessage = null;

    public function mount(Account $account): void
    {
        abort_unless($account->user_id === auth()->id(), 404);
        abort_unless($account->redbarkAccount()->exists(), 404);

        $this->account = $account;

        if (! $this->isValidMonth($this->month)) {
            $this->month = InitialSyncWindow::start(CarbonImmutable::now())->format(self::MONTH_FORMAT);
        }
    }

    public function updatedMonth(): void
    {
        if (! $this->isValidMonth($this->month)) {
            $this->month = InitialSyncWindow::start(CarbonImmutable::now())->format(self::MONTH_FORMAT);
        }

        $this->cancelUpload();
        unset($this->reconciliation);
    }

    public function startUpload(): void
    {
        $this->uploading = true;
    }

    public function cancelUpload(): void
    {
        $this->discardPendingFile();
        $this->reset(['file', 'uploading', 'headers', 'mapping', 'storedPath', 'originalFilename', 'errorMessage']);
    }

    public function uploadFile(CsvParserService $parser, CsvColumnMapper $mapper): void
    {
        $this->errorMessage = null;

        $this->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $file = $this->file;

        if ($file === null) {
            return;
        }

        if ($this->reconciliation !== null && ! $this->reconciliation->isOpen()) {
            $this->errorMessage = __('This month is closed — reopen it first');

            return;
        }

        $storedPath = $file->store('statement-reconciliations', 'local');

        if ($storedPath === false) {
            $this->errorMessage = __('The file could not be stored. Please try again.');

            return;
        }

        try {
            $this->headers = $parser->headers(Storage::disk('local')->path($storedPath));
        } catch (Throwable) {
            Storage::disk('local')->delete($storedPath);
            $this->errorMessage = __('That file could not be read as a CSV.');

            return;
        }

        $this->discardPendingFile();
        $this->storedPath = $storedPath;
        $this->originalFilename = $file->getClientOriginalName();

        // A saved mapping only applies where this file still has that column; a bank that
        // renamed a header falls back to the suggestion instead of an unparseable mapping.
        $saved = array_filter(
            $this->account->column_mapping ?? [],
            fn (?string $column): bool => $column !== null && in_array($column, $this->headers, true),
        );
        $this->mapping = array_merge($mapper->suggest($this->headers), $saved);
    }

    public function reconcile(StatementReconciler $reconciler): void
    {
        $this->errorMessage = null;

        if ($this->storedPath === null || $this->originalFilename === null) {
            $this->errorMessage = __('Upload a statement first.');

            return;
        }

        $column = Rule::in($this->headers);

        $this->validate([
            'mapping' => ['required', 'array'],
            'mapping.date' => ['required', 'string', $column],
            'mapping.description' => ['nullable', 'string', $column],
            'mapping.amount' => ['nullable', 'string', 'required_without_all:mapping.debit,mapping.credit', $column],
            'mapping.debit' => ['nullable', 'string', $column],
            'mapping.credit' => ['nullable', 'string', $column],
            'mapping.balance' => ['nullable', 'string', $column],
        ], [
            'mapping.*.in' => __('That column is not in this file. Pick one of its headers.'),
        ]);

        if (! empty($this->mapping['amount']) && (! empty($this->mapping['debit']) || ! empty($this->mapping['credit']))) {
            $this->addError('mapping.amount', __('Map either a signed amount column OR debit/credit columns, not both.'));

            return;
        }

        $existing = $this->reconciliation;

        if ($existing !== null && ! $existing->isOpen()) {
            $this->errorMessage = __('This month is closed — reopen it first');

            return;
        }

        $periodStart = $this->periodStart();
        $previousPath = $existing?->stored_path;

        try {
            // Saving the new file and mapping and rebuilding the lines succeed or fail
            // together: a statement the mapping cannot read leaves the previous build intact.
            DB::transaction(function () use ($existing, $periodStart, $reconciler): void {
                // forPeriod matches on the date part; a plain updateOrCreate would compare the
                // stored datetime string against a bare date and miss the existing row.
                $reconciliation = $existing ?? new StatementReconciliation([
                    'account_id' => $this->account->id,
                    'period_start' => $periodStart,
                ]);

                $reconciliation->fill([
                    'user_id' => $this->account->user_id,
                    'period_end' => $periodStart->endOfMonth()->startOfDay(),
                    'original_filename' => $this->originalFilename,
                    'stored_path' => $this->storedPath,
                    'column_mapping' => $this->mapping,
                ])->save();

                $this->account->update(['column_mapping' => $this->mapping]);

                $reconciler->build($reconciliation);
            });
        } catch (StatementFileUnreadable|StatementReconciliationClosed $e) {
            $this->errorMessage = $e->getMessage();
            $this->account->refresh();
            unset($this->reconciliation);

            return;
        }

        if ($previousPath !== null && $previousPath !== $this->storedPath) {
            Storage::disk('local')->delete($previousPath);
        }

        $this->reset(['file', 'uploading', 'headers', 'mapping', 'storedPath', 'originalFilename']);
        unset($this->reconciliation);
    }

    public function tick(int $lineId): void
    {
        $this->setChecked($lineId, true);
    }

    public function untick(int $lineId): void
    {
        $this->setChecked($lineId, false);
    }

    public function tickAll(string $kind): void
    {
        $this->errorMessage = null;
        $lineKind = StatementLineKind::tryFrom($kind);

        if (! in_array($lineKind, [StatementLineKind::Matched, StatementLineKind::FeedOnly], true)) {
            return;
        }

        $reconciliation = $this->openReconciliation();

        $reconciliation?->lines()
            ->where('kind', $lineKind)
            ->whereNull('checked_at')
            ->update(['checked_at' => now()]);
    }

    public function import(int $lineId, StatementReconciler $reconciler): void
    {
        $line = $this->line($lineId);

        if ($line !== null) {
            $this->attempt(fn () => $reconciler->resolveImport($line));
        }
    }

    public function link(int $lineId, int $feedOnlyLineId, StatementReconciler $reconciler): void
    {
        $line = $this->line($lineId);
        $feedOnly = $line !== null ? $this->line($feedOnlyLineId) : null;

        if ($line === null || $feedOnly === null) {
            return;
        }

        $this->attempt(fn () => $reconciler->resolveLink($line, $feedOnly));
    }

    public function ignore(int $lineId, string $note, StatementReconciler $reconciler): void
    {
        $this->resetErrorBag("ignore.{$lineId}");
        $note = mb_trim($note);

        if ($note === '' || mb_strlen($note) > 255) {
            $this->addError("ignore.{$lineId}", __('Add a short note (up to 255 characters) explaining why this line is ignored.'));

            return;
        }

        $line = $this->line($lineId);

        if ($line !== null) {
            $this->attempt(fn () => $reconciler->resolveIgnore($line, $note));
        }
    }

    public function close(StatementReconciler $reconciler): void
    {
        $reconciliation = $this->reconciliation;

        if ($reconciliation !== null) {
            $this->attempt(fn () => $reconciler->close($reconciliation));
        }
    }

    public function reopen(StatementReconciler $reconciler): void
    {
        $this->errorMessage = null;
        $reconciliation = $this->reconciliation;

        if ($reconciliation !== null) {
            $reconciler->reopen($reconciliation);
        }
    }

    #[Computed]
    public function reconciliation(): ?StatementReconciliation
    {
        return StatementReconciliation::query()
            ->forPeriod($this->account->id, $this->periodStart())
            ->first();
    }

    /** @return Collection<int, StatementReconciliationLine> */
    #[Computed]
    public function lines(): Collection
    {
        $reconciliation = $this->reconciliation;

        if ($reconciliation === null) {
            return new Collection();
        }

        return $reconciliation->lines()
            ->with('transaction')
            ->orderBy('post_date')
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, string> YYYY-MM => label, newest first */
    #[Computed]
    public function monthOptions(): array
    {
        $options = [];
        $month = CarbonImmutable::now()->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $options[$month->format(self::MONTH_FORMAT)] = $month->format('F Y');
            $month = $month->subMonthNoOverflow();
        }

        if (! isset($options[$this->month])) {
            $options[$this->month] = $this->periodStart()->format('F Y');
        }

        return $options;
    }

    public function render(CsvParserService $parser): View
    {
        $previewRows = [];

        if ($this->storedPath !== null && $this->mapping !== []) {
            try {
                $previewRows = $parser->preview(Storage::disk('local')->path($this->storedPath), $this->mapping, limit: 5);
            } catch (Throwable) {
                // The user is still adjusting the mapping.
            }
        }

        $lines = $this->lines;
        $matched = $lines->where('kind', StatementLineKind::Matched);

        return view('livewire.reconcile-statement', [
            'previewRows' => $previewRows,
            'statementOnly' => $lines->where('kind', StatementLineKind::StatementOnly)->values(),
            'feedOnly' => $lines->where('kind', StatementLineKind::FeedOnly)->values(),
            'matched' => $matched->values(),
            'statementLineCount' => $lines->where('kind', '!==', StatementLineKind::FeedOnly)->count(),
            'matchedDebits' => (int) $matched->where('amount', '<', 0)->sum(fn (StatementReconciliationLine $line): int => abs($line->amount)),
            'matchedCredits' => (int) $matched->where('amount', '>', 0)->sum('amount'),
            'fields' => [
                CsvColumnMapper::FIELD_DATE => 'Date',
                CsvColumnMapper::FIELD_DESCRIPTION => 'Description',
                CsvColumnMapper::FIELD_AMOUNT => 'Signed amount',
                CsvColumnMapper::FIELD_DEBIT => 'Debit (out)',
                CsvColumnMapper::FIELD_CREDIT => 'Credit (in)',
                CsvColumnMapper::FIELD_BALANCE => 'Balance',
            ],
        ])->title(__('Reconcile :account', ['account' => $this->account->name]));
    }

    private function periodStart(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!'.self::MONTH_FORMAT, $this->month)?->startOfMonth()
            ?? InitialSyncWindow::start(CarbonImmutable::now());
    }

    private function isValidMonth(string $month): bool
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            return false;
        }

        return CarbonImmutable::createFromFormat('!'.self::MONTH_FORMAT, $month) !== null;
    }

    private function openReconciliation(): ?StatementReconciliation
    {
        $reconciliation = $this->reconciliation;

        if ($reconciliation === null) {
            return null;
        }

        if (! $reconciliation->isOpen()) {
            $this->errorMessage = __('This month is closed — reopen it first');

            return null;
        }

        return $reconciliation;
    }

    /** A line of the reconciliation on screen; ids from the wire are never trusted. */
    private function line(int $lineId): ?StatementReconciliationLine
    {
        $this->errorMessage = null;
        $line = $this->reconciliation?->lines()->whereKey($lineId)->first();

        if ($line === null) {
            $this->errorMessage = __('That line is not part of this reconciliation.');
        }

        return $line;
    }

    private function setChecked(int $lineId, bool $checked): void
    {
        $line = $this->line($lineId);

        if ($line === null || $this->openReconciliation() === null) {
            return;
        }

        if ($checked && $line->kind === StatementLineKind::StatementOnly && $line->resolution === null) {
            $this->errorMessage = __('Add, link or ignore this statement line before ticking it.');

            return;
        }

        $line->update(['checked_at' => $checked ? now() : null]);
    }

    /** Deletes an uploaded file that never became (or no longer is) a reconciliation's file. */
    private function discardPendingFile(): void
    {
        if ($this->storedPath !== null && $this->storedPath !== $this->reconciliation?->stored_path) {
            Storage::disk('local')->delete($this->storedPath);
        }

        $this->storedPath = null;
    }

    /** Runs a reconciler mutation, surfacing its domain errors inline. */
    private function attempt(callable $mutation): void
    {
        $this->errorMessage = null;

        try {
            $mutation();
        } catch (StatementLineAlreadyFolded|StatementLineNotResolvable|StatementReconciliationClosed|StatementReconciliationIncomplete $e) {
            $this->errorMessage = $e->getMessage();
        }
    }
}
