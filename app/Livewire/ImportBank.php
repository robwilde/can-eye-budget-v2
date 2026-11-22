<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\CsvFormat;
use App\Enums\BankImportStatus;
use App\Enums\CsvNumberFormat;
use App\Enums\CsvSignConvention;
use App\Enums\ImportPreset;
use App\Enums\ImportSource;
use App\Exceptions\BankImportRevertRefused;
use App\Exceptions\DocumentRefused;
use App\Exceptions\Statement\PdfImportRefused;
use App\Jobs\ImportCsvTransactionsJob;
use App\Livewire\Attributes\NotAudited;
use App\Livewire\Concerns\ReportsFailure;
use App\Models\Account;
use App\Models\BankImport;
use App\Models\BankImportRow;
use App\Models\Category;
use App\Models\Tag;
use App\Models\Transaction;
use App\Services\CsvImport\BankImportReverter;
use App\Services\CsvImport\BankImportRowBuilder;
use App\Services\CsvImport\BankImportRowEditor;
use App\Services\CsvImport\BankImportRowSummary;
use App\Services\CsvImport\BankImportValueMapping;
use App\Services\CsvImport\CsvColumnMapper;
use App\Services\CsvImport\CsvFormatDetector;
use App\Services\CsvImport\CsvParserService;
use App\Services\CsvImport\ImportPresetMapper;
use App\Services\CsvImport\QifParserService;
use App\Services\DocumentUploadService;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\Statement\PdfImportStarter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use League\Csv\Exception;
use League\Csv\SyntaxError;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use RuntimeException;
use Throwable;

final class ImportBank extends Component
{
    use ReportsFailure;
    use WithFileUploads;
    use WithPagination;

    public int $step = 1;

    #[Validate('nullable|file|extensions:csv,txt,qif|max:10240')]
    public ?TemporaryUploadedFile $file = null;

    public ?TemporaryUploadedFile $pdfFile = null;

    public ?TemporaryUploadedFile $documentFile = null;

    public ?string $documentMessage = null;

    public ?int $accountId = null;

    public string $accountChoice = 'existing';

    #[Validate('nullable|string|max:255')]
    public string $newAccountName = '';

    #[Validate('nullable|string|size:4')]
    public string $newAccountLast4 = '';

    #[Validate('nullable|numeric')]
    public string $newAccountBalance = '';

    public string $newAccountCurrency = '';

    #[Validate('nullable|numeric')]
    public string $currentBalance = '';

    public bool $balanceTouched = false;

    /** @var list<string> */
    public array $headers = [];

    /** @var array<string, string|null> */
    public array $mapping = [];

    public ?int $bankImportId = null;

    public string $importPreset = 'bank_csv';

    public string $qifDateOrder = QifParserService::ORDER_AUTO;

    public bool $cleaning = false;

    public bool $errorsOnly = false;

    public static function maxPdfKilobytes(): int
    {
        return min(25600, intdiv((int) config('services.statement_ai.max_pdf_bytes'), 1024));
    }

    public function updatedErrorsOnly(): void
    {
        $this->resetPage('rowsPage');
    }

    /**
     * Stores a non-PDF document for later search. A PDF goes through the PDF import instead.
     */
    public function importDocument(DocumentUploadService $uploads, PdfImportStarter $starter): void
    {
        $this->clearFailure();
        $this->documentMessage = null;

        $this->validate(['documentFile' => ['required', 'file']]);

        if (mb_strtolower($this->documentFile->getClientOriginalExtension()) === 'pdf') {
            if (! config('budget.pdf_import.enabled')) {
                $this->fail(__('PDF import is not enabled.'));

                return;
            }

            $this->pdfFile = $this->documentFile;
            $this->documentFile = null;

            try {
                $this->startPdfImport($starter);
            } catch (ValidationException $exception) {
                $this->fail(collect($exception->errors())->flatten()->first() ?? __('That PDF could not be imported.'));
            }

            return;
        }

        try {
            $uploads->store(auth()->user(), $this->documentFile);
        } catch (DocumentRefused $exception) {
            $this->fail($exception->getMessage());

            return;
        }

        $this->documentFile = null;
        $this->documentMessage = __('Document stored.');
    }

    /**
     * Files a statement PDF in the vault and queues its AI extraction, then opens the review.
     */
    public function startPdfImport(PdfImportStarter $starter): void
    {
        $this->clearFailure();

        $this->validate(['pdfFile' => ['required', 'file', 'mimes:pdf', 'max:'.self::maxPdfKilobytes()]]);

        $account = $this->accountChoice === 'existing' && $this->accountId !== null
            ? Account::query()->where('user_id', auth()->id())->find($this->accountId)
            : null;

        try {
            $import = $starter->start(auth()->user(), $this->pdfFile, $account);
        } catch (PdfImportRefused $exception) {
            $this->fail($exception->getMessage());

            return;
        }

        $this->redirectRoute('imports.pdf.review', ['pdfImport' => $import->id], navigate: true);
    }

    /**
     * @throws SyntaxError
     * @throws Exception
     */
    public function uploadAndDetectHeaders(CsvParserService $parser, CsvColumnMapper $mapper): void
    {
        $this->clearFailure();

        $preset = ImportPreset::tryFrom($this->importPreset) ?? ImportPreset::BankCsv;

        $this->validate([
            'file' => ['required', 'file', $preset->isQif() ? 'extensions:qif,txt' : 'mimes:csv,txt', 'max:10240'],
        ]);

        $account = $this->resolveOrCreateAccount();

        if ($account === null) {
            return;
        }

        $this->accountId = $account->id;

        $storedPath = $this->file->store('bank-imports', 'local');
        $absolutePath = Storage::disk('local')->path($storedPath);

        if ($preset->isQif()) {
            $this->openQifImport($account, $storedPath);

            return;
        }

        $existingMapping = $preset->usesBankMapping() ? ($account->column_mapping ?? []) : [];
        $detector = app(CsvFormatDetector::class);
        $first = fopen($absolutePath, 'rb');
        $firstLine = $first === false ? '' : (string) fgets($first);

        if ($first !== false) {
            fclose($first);
        }

        $format = CsvFormat::fromMapping($existingMapping) ?? new CsvFormat(delimiter: $detector->delimiter($firstLine));

        try {
            $this->headers = $parser->headers($absolutePath, $format);
        } catch (Throwable) {
            Storage::disk('local')->delete($storedPath);
            $this->fail(__('That file could not be read with its saved format settings. Check the delimiter and rows to skip.'));

            return;
        }

        $mapping = $existingMapping !== []
            ? array_merge($mapper->suggest($this->headers), $existingMapping)
            : $mapper->suggest($this->headers);

        if (! $preset->usesBankMapping()) {
            $accounts = $this->accountsInFile($preset, $parser, $absolutePath, $format);

            if (count($accounts) > 1) {
                Storage::disk('local')->delete($storedPath);
                $this->headers = [];
                $this->fail(__('That file holds more than one account (:names). Export one account at a time.', ['names' => implode(', ', $accounts)]));

                return;
            }

            $mapping = array_merge($mapping, $this->presetColumns($preset, $parser, $absolutePath, $format));
        }

        $this->mapping = array_merge($this->formatDefaults($parser, $absolutePath, $mapping, $format), $mapping);

        $this->bankImportId = BankImport::query()->create([
            'user_id' => auth()->id(),
            'account_id' => $account->id,
            'original_filename' => $this->file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'status' => BankImportStatus::Previewing,
            'preset' => $preset,
            'column_mapping' => $this->mapping,
        ])->id;

        $this->step = 2;
    }

    /**
     * Reads the dates of a QIF file in the order the user picked and builds the rows again.
     */
    public function updatedQifDateOrder(): void
    {
        $bankImport = $this->currentImport();

        if ($bankImport->preset !== ImportPreset::Qif) {
            return;
        }

        if (! in_array($this->qifDateOrder, QifParserService::ORDERS, true)) {
            $this->qifDateOrder = QifParserService::ORDER_AUTO;
        }

        $bankImport->update(['column_mapping' => [BankImportRowBuilder::QIF_DATE_ORDER => $this->qifDateOrder]]);

        try {
            app(BankImportRowBuilder::class)->build($bankImport, suggest: true);
        } catch (Throwable) {
            $this->fail(__('The file could not be read with that date order.'));
        }
    }

    /**
     * Changing the delimiter or the rows to skip changes the file's headers, so they are read
     * again; changing a column or the date format only re-checks the date format on offer.
     */
    public function updatedMapping(mixed $value = null, ?string $key = null): void
    {
        $parser = app(CsvParserService::class);
        $path = $this->storedPath();

        if ($path === null) {
            return;
        }

        $this->forgetRows();

        if ($key !== null && in_array($key, [CsvFormat::KEY_DELIMITER, CsvFormat::KEY_SKIP], true)) {
            $format = CsvFormat::fromMapping($this->mapping);

            try {
                $this->headers = $parser->headers($path, $format);
            } catch (Throwable) {
                return;
            }

            $kept = array_intersect_key($this->mapping, array_flip([
                CsvFormat::KEY_DATE, CsvFormat::KEY_NUMBER, CsvFormat::KEY_SIGN, CsvFormat::KEY_SKIP, CsvFormat::KEY_DELIMITER, CsvFormat::KEY_INFLOW,
            ]));
            $this->mapping = array_merge(app(CsvColumnMapper::class)->suggest($this->headers), $kept);
        }

        $options = $this->dateFormatOptions($parser, $path);

        if ($key !== CsvFormat::KEY_DATE && $options !== [] && ! in_array($this->mapping[CsvFormat::KEY_DATE] ?? null, $options, true)) {
            $this->mapping[CsvFormat::KEY_DATE] = $options[0];
        }
    }

    /**
     * Opens the rows table. The file is parsed into editable rows the first time, with a starting
     * choice for each category and tag value in it; reopening keeps the user's edits and choices.
     */
    public function reviewRows(BankImportRowBuilder $builder): void
    {
        $this->clearFailure();
        $this->resetErrorBag();

        $bankImport = $this->currentImport();

        try {
            if (! $bankImport->rows()->exists()) {
                $bankImport->update(['column_mapping' => $this->mapping]);
                $builder->build($bankImport, suggest: true);
            } elseif ($bankImport->value_mapping === null) {
                $bankImport->update(['value_mapping' => app(BankImportValueMapping::class)->suggest($bankImport)]);
            }
        } catch (Throwable) {
            $this->fail(__('The file could not be read with these settings.'));

            return;
        }

        $this->cleaning = true;
        $this->resetPage('rowsPage');
    }

    public function closeRows(): void
    {
        $this->cleaning = false;
    }

    /**
     * Edits one cell of an import row and checks the row again.
     */
    public function updateRow(int $rowId, string $field, string $value, BankImportRowEditor $editor): void
    {
        $this->resetErrorBag();

        $row = $this->ownedRow($rowId);

        if ($row === null || ! in_array($field, BankImportRowEditor::FIELDS, true)) {
            $this->addError('rows', __('That row is no longer available.'));

            return;
        }

        $editor->update($row, $field, $value);
    }

    public function mapCategory(string $key, string $categoryId): void
    {
        $bankImport = $this->currentImport();
        $value = collect(app(BankImportValueMapping::class)->categoryValues($bankImport))->first(fn (string $candidate): bool => BankImportValueMapping::key($candidate) === $key);

        if ($value === null) {
            return;
        }

        $id = $categoryId === '' ? null : (int) $categoryId;

        if ($id !== null && ! Category::query()->where('user_id', auth()->id())->whereKey($id)->exists()) {
            $this->addError('value_mapping', __('Choose one of your own categories.'));

            return;
        }

        $mapping = $bankImport->value_mapping ?? ['categories' => [], 'tags' => []];
        $mapping['categories'][$value] = $id;
        $bankImport->update(['value_mapping' => $mapping]);
    }

    public function mapTag(string $key, string $choice): void
    {
        $bankImport = $this->currentImport();
        $value = collect(app(BankImportValueMapping::class)->tagValues($bankImport))->first(fn (string $candidate): bool => BankImportValueMapping::key($candidate) === $key);

        if ($value === null) {
            return;
        }

        $mapped = match (true) {
            $choice === '' => null,
            $choice === BankImportValueMapping::TAG_NEW => ['type' => BankImportValueMapping::TAG_NEW],
            ctype_digit($choice) && Tag::query()->where('user_id', auth()->id())->whereKey((int) $choice)->exists() => ['type' => BankImportValueMapping::TAG_EXISTING, 'id' => (int) $choice],
            default => false,
        };

        if ($mapped === false) {
            $this->addError('value_mapping', __('Choose one of your own tags.'));

            return;
        }

        $mapping = $bankImport->value_mapping ?? ['categories' => [], 'tags' => []];
        $mapping['tags'][$value] = $mapped;
        $bankImport->update(['value_mapping' => $mapping]);
    }

    public function refreshPreview(): void
    {
        // Preview/summary are recomputed in render() — this hook just triggers a re-render.
    }

    public function confirmImport(CsvParserService $parser): void
    {
        $this->clearFailure();

        if ($this->bankImportId === null || $this->accountId === null) {
            $this->fail('No import in progress.');

            return;
        }

        $account = Account::query()
            ->whereKey($this->accountId)
            ->where('user_id', auth()->id())
            ->tracked()
            ->first();

        if ($account === null || ! $account->acceptsCsvImports()) {
            $this->addError('account_id', 'You cannot import a CSV into an account connected via your bank.');

            return;
        }

        $bankImport = BankImport::query()
            ->where('user_id', auth()->id())
            ->findOrFail($this->bankImportId);

        $isQif = $bankImport->preset === ImportPreset::Qif;

        if ($isQif) {
            $this->validate(['currentBalance' => ['nullable', 'numeric']]);
        } elseif (! $this->mappingIsValid()) {
            return;
        }

        if (! $this->formatAllowsImport($parser, $bankImport)) {
            return;
        }

        // accountId is a mutable Livewire property: the persisted import must belong to the
        // very (tracked) account being confirmed, or a forged swap could redirect it.
        if ($bankImport->account_id !== $account->id) {
            $this->addError('account_id', 'This import belongs to a different account.');

            return;
        }

        $bankImport->update($isQif
            ? ['status' => BankImportStatus::Pending]
            : ['status' => BankImportStatus::Pending, 'column_mapping' => $this->mapping]);

        if ($bankImport->preset->usesBankMapping()) {
            Account::query()
                ->whereKey($this->accountId)
                ->update(['column_mapping' => $this->mapping]);
        }

        if ($this->currentBalance !== '') {
            Account::query()->getConnection()->transaction(function () use ($account): void {
                $account->update([
                    'balance' => (int) round(((float) $this->currentBalance) * 100),
                    'balance_source' => ImportSource::Csv,
                    'balance_updated_at' => now(),
                ]);

                if ($account->wasChanged('balance')) {
                    app(AccountBalanceHistoryBuilder::class)->rebuild($account);
                }
            });
        }

        ImportCsvTransactionsJob::dispatch($bankImport);

        $this->step = 3;
    }

    /**
     * Undoes the completed import and returns to the preview, so it can be confirmed again
     * from its stored file. A failure changes nothing and can be retried.
     */
    public function revertImport(BankImportReverter $reverter): void
    {
        $this->clearFailure();

        $bankImport = BankImport::query()->where('user_id', auth()->id())->findOrFail($this->bankImportId);

        try {
            $reverter->revert($bankImport);
        } catch (BankImportRevertRefused $exception) {
            $this->fail($exception->getMessage());

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->fail(__('The import could not be reverted. Nothing was changed; please try again.'));

            return;
        }

        $this->step = 2;
    }

    #[NotAudited]
    public function pollStatus(): void
    {
        if ($this->bankImportId === null) {
            return;
        }

        $bankImport = BankImport::query()
            ->where('user_id', auth()->id())
            ->find($this->bankImportId);

        if ($bankImport === null) {
            return;
        }

        if ($bankImport->status->isTerminal()) {
            $this->dispatch('csv-import-complete', status: $bankImport->status->value);
        }
    }

    public function startOver(): void
    {
        $this->reset([
            'step',
            'file',
            'accountId',
            'newAccountName',
            'newAccountLast4',
            'newAccountBalance',
            'newAccountCurrency',
            'currentBalance',
            'balanceTouched',
            'headers',
            'mapping',
            'bankImportId',
            'errorMessage',
            'cleaning',
            'errorsOnly',
            'qifDateOrder',
        ]);
        $this->resetErrorBag('errorMessage');
        $this->resetPage('rowsPage');

        $this->step = 1;
    }

    public function updatedCurrentBalance(): void
    {
        $this->balanceTouched = true;
    }

    #[Computed]
    public function lastImportedDate(): ?CarbonImmutable
    {
        if ($this->accountId === null || $this->accountChoice !== 'existing') {
            return null;
        }

        return Transaction::lastImportedPostDate((int) auth()->id(), $this->accountId);
    }

    public function render(CsvParserService $parser): View
    {
        $userAccounts = auth()->user()->accounts()
            ->tracked()
            ->visible()
            ->where(fn ($query) => $query->active()->orWhere('id', $this->accountId))
            ->get();
        $bankImport = $this->bankImportId !== null
            ? BankImport::query()->where('user_id', auth()->id())->find($this->bankImportId)
            : null;

        $previewRows = [];
        $summary = null;

        $isQif = $bankImport?->preset === ImportPreset::Qif;

        if ($this->step >= 2 && $bankImport !== null && $isQif) {
            $summary = app(BankImportRowSummary::class)->for($bankImport);
        } elseif ($this->step >= 2 && $bankImport !== null) {
            $absolutePath = Storage::disk('local')->path($bankImport->stored_path);

            try {
                $previewRows = $parser->preview($absolutePath, $this->mapping, limit: 10);
                $summary = $parser->summarize($absolutePath, $this->mapping);
            } catch (Throwable) {
                // ignore preview failures — user is still adjusting the mapping
            }
        }

        $continuity = null;

        if ($summary?->earliestDate !== null && $this->accountChoice === 'existing' && $this->accountId !== null) {
            $lastImported = Transaction::lastImportedPostDate((int) auth()->id(), $this->accountId);

            if ($lastImported !== null) {
                $continuity = [
                    'status' => $summary->earliestDate->greaterThan($lastImported) ? 'gap' : 'continuous',
                    'lastImportedDate' => $lastImported,
                    'fileEarliest' => $summary->earliestDate,
                ];
            }
        }

        if ($this->accountChoice === 'existing' && ! $this->balanceTouched) {
            $this->currentBalance = $summary?->closingBalance !== null
                ? number_format($summary->closingBalance / 100, 2, '.', '')
                : '';
        }

        return view('livewire.import-bank', [
            'accounts' => $userAccounts,
            'currencies' => auth()->user()->allowedCurrencies(),
            'bankImport' => $bankImport,
            'previewRows' => $previewRows,
            'summary' => $summary,
            'continuity' => $continuity,
            'format' => CsvFormat::fromMapping($this->mapping),
            'storedInvalid' => $bankImport !== null && $this->step === 2 && $bankImport->rows()->exists() ? $bankImport->rows()->invalid()->count() : null,
            'isQif' => $isQif,
            'presets' => ImportPreset::cases(),
            'qifOrders' => [QifParserService::ORDER_AUTO => __('Detect from the file'), QifParserService::ORDER_DAY_FIRST => __('Day first (31/12/2024)'), QifParserService::ORDER_MONTH_FIRST => __('Month first (12/31/2024)')],
            'clean' => $this->cleaning && $bankImport !== null ? $this->cleanData($bankImport) : null,
            'dateFormatOptions' => $this->step === 2 && $bankImport !== null && ! $isQif
                ? $this->dateFormatOptions($parser, Storage::disk('local')->path($bankImport->stored_path))
                : [],
            'fields' => [
                CsvColumnMapper::FIELD_DATE => 'Date',
                CsvColumnMapper::FIELD_DESCRIPTION => 'Description',
                CsvColumnMapper::FIELD_AMOUNT => 'Signed amount',
                CsvColumnMapper::FIELD_DEBIT => 'Debit (out)',
                CsvColumnMapper::FIELD_CREDIT => 'Credit (in)',
                CsvColumnMapper::FIELD_BALANCE => 'Balance',
                CsvColumnMapper::FIELD_CATEGORY => 'Category',
                CsvColumnMapper::FIELD_TAG => 'Tags',
            ],
        ]);
    }

    private function mappingIsValid(): bool
    {
        $this->validate([
            'mapping' => ['required', 'array'],
            'mapping.date' => ['required', 'string'],
            'mapping.amount' => ['nullable', 'string', 'required_without_all:mapping.debit,mapping.credit'],
            'mapping.debit' => ['nullable', 'string'],
            'mapping.credit' => ['nullable', 'string'],
            'currentBalance' => ['nullable', 'numeric'],
            'mapping.'.CsvFormat::KEY_DATE => ['nullable', Rule::in(array_keys(CsvFormat::DATE_FORMATS))],
            'mapping.'.CsvFormat::KEY_NUMBER => ['nullable', Rule::enum(CsvNumberFormat::class)],
            'mapping.'.CsvFormat::KEY_SIGN => ['nullable', Rule::enum(CsvSignConvention::class)],
            'mapping.'.CsvFormat::KEY_SKIP => ['nullable', 'integer', 'min:0'],
            'mapping.'.CsvFormat::KEY_DELIMITER => ['nullable', Rule::in(CsvFormat::DELIMITERS)],
            'mapping.'.CsvFormat::TYPE_COLUMN => [Rule::requiredIf(($this->mapping[CsvFormat::KEY_SIGN] ?? null) === CsvSignConvention::TypeColumn->value), 'nullable', 'string'],
        ]);

        if (! empty($this->mapping['amount']) && (! empty($this->mapping['debit']) || ! empty($this->mapping['credit']))) {
            $this->addError('mapping.amount', 'Map either a signed amount column OR debit/credit columns, not both.');

            return false;
        }

        return true;
    }

    private function csvRowsReady(CsvParserService $parser, BankImport $bankImport, CsvFormat $format): bool
    {
        $path = Storage::disk('local')->path($bankImport->stored_path);

        try {
            $lines = $parser->lineCount($path, $format);

            if ($format->skipRows >= $lines) {
                $this->addError('mapping.'.CsvFormat::KEY_SKIP, __('Rows to skip must be fewer than the :count lines in the file.', ['count' => $lines]));

                return false;
            }

            if (! $bankImport->rows()->exists()) {
                $bankImport->update(['column_mapping' => $this->mapping]);
                app(BankImportRowBuilder::class)->build($bankImport);
            }
        } catch (Throwable) {
            $this->addError('mapping.'.CsvFormat::KEY_DATE, __('The file could not be read with these format settings.'));

            return false;
        }

        return true;
    }

    /**
     * With a chosen format, the rows to skip must leave at least one line and every row must fit
     * the format, so a wrong date or number format is fixed before anything is imported.
     */
    private function formatAllowsImport(CsvParserService $parser, BankImport $bankImport): bool
    {
        if ($bankImport->preset !== ImportPreset::Qif) {
            $format = CsvFormat::fromMapping($this->mapping);

            if ($format === null) {
                return true;
            }

            if (! $this->csvRowsReady($parser, $bankImport, $format)) {
                return false;
            }
        }

        $invalid = $bankImport->rows()->invalid()->count();

        if ($invalid > 0) {
            $this->cleaning = true;
            $this->addError($bankImport->preset === ImportPreset::Qif ? 'rows' : 'mapping.'.CsvFormat::KEY_DATE, trans_choice(':count row is invalid. Fix it in the rows table before importing.|:count rows are invalid. Fix them in the rows table before importing.', $invalid));

            return false;
        }

        $problems = app(BankImportValueMapping::class)->problems($bankImport);

        if ($problems !== []) {
            $this->cleaning = true;
            $this->addError('value_mapping', implode(' ', $problems));

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, string|null>  $mapping
     * @return array<string, string>
     */
    private function formatDefaults(CsvParserService $parser, string $path, array $mapping, CsvFormat $format): array
    {
        $dateColumn = $mapping[CsvColumnMapper::FIELD_DATE] ?? null;
        try {
            $options = $dateColumn === null ? [] : app(CsvFormatDetector::class)->dateFormats($parser->sampleValues($path, $dateColumn, $format));
        } catch (Throwable) {
            $options = [];
        }

        return new CsvFormat(
            dateFormat: $options[0] ?? $format->dateFormat,
            numberFormat: $format->numberFormat,
            signConvention: $format->signConvention,
            skipRows: $format->skipRows,
            delimiter: $format->delimiter,
            typeInflowValue: $format->typeInflowValue,
        )->toMapping();
    }

    /**
     * @return list<string>
     */
    private function dateFormatOptions(CsvParserService $parser, string $path): array
    {
        $dateColumn = $this->mapping[CsvColumnMapper::FIELD_DATE] ?? null;

        if ($dateColumn === null || $dateColumn === '') {
            return [];
        }

        try {
            return app(CsvFormatDetector::class)->dateFormats($parser->sampleValues($path, $dateColumn, CsvFormat::fromMapping($this->mapping)));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanData(BankImport $bankImport): array
    {
        $values = app(BankImportValueMapping::class);
        $rows = $bankImport->rows()->when($this->errorsOnly, fn ($query) => $query->invalid())->orderBy('row_number')->paginate(25, pageName: 'rowsPage');

        return [
            'rows' => $rows,
            'format' => CsvFormat::fromMapping($this->mapping) ?? CsvFormat::fromMapping($bankImport->column_mapping ?? []),
            'total' => $bankImport->rows()->count(),
            'invalid' => $bankImport->rows()->invalid()->count(),
            'categoryValues' => $values->categoryValues($bankImport),
            'tagValues' => $values->tagValues($bankImport),
            'mapping' => $bankImport->value_mapping ?? ['categories' => [], 'tags' => []],
            'categories' => Category::query()->where('user_id', auth()->id())->orderBy('name')->get(['id', 'name']),
            'tags' => Tag::query()->where('user_id', auth()->id())->orderBy('name')->get(['id', 'name']),
        ];
    }

    private function forgetRows(): void
    {
        if ($this->bankImportId === null) {
            return;
        }

        $bankImport = BankImport::query()->where('user_id', auth()->id())->find($this->bankImportId);

        if ($bankImport !== null) {
            $bankImport->rows()->delete();
            $bankImport->update(['value_mapping' => null]);
        }

        $this->cleaning = false;
    }

    private function currentImport(): BankImport
    {
        return BankImport::query()->where('user_id', auth()->id())->findOrFail($this->bankImportId);
    }

    private function ownedRow(int $rowId): ?BankImportRow
    {
        return BankImportRow::query()->forUser((int) auth()->id())->where('bank_import_id', $this->bankImportId)->with('bankImport')->find($rowId);
    }

    private function openQifImport(Account $account, string $storedPath): void
    {
        $this->headers = [];
        $this->mapping = [];
        $this->qifDateOrder = QifParserService::ORDER_AUTO;

        $bankImport = BankImport::query()->create([
            'user_id' => auth()->id(),
            'account_id' => $account->id,
            'original_filename' => $this->file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'status' => BankImportStatus::Previewing,
            'preset' => ImportPreset::Qif,
            'column_mapping' => [BankImportRowBuilder::QIF_DATE_ORDER => $this->qifDateOrder],
        ]);

        try {
            app(BankImportRowBuilder::class)->build($bankImport, suggest: true);
        } catch (Throwable $exception) {
            $bankImport->delete();
            Storage::disk('local')->delete($storedPath);
            $this->fail($exception instanceof RuntimeException ? __($exception->getMessage()) : __('That file could not be read as a QIF file.'));

            return;
        }

        $this->bankImportId = $bankImport->id;
        $this->cleaning = true;
        $this->step = 2;
    }

    /**
     * @return list<string> the first two different accounts the file names
     */
    private function accountsInFile(ImportPreset $preset, CsvParserService $parser, string $path, CsvFormat $readFormat): array
    {
        $column = app(ImportPresetMapper::class)->accountColumn($preset, $this->headers);

        if ($column === null) {
            return [];
        }

        try {
            return $parser->distinctValues($path, $column, $readFormat);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The columns and format of a Mint, YNAB or Actual Budget export. The preset's date format is
     * kept when every date in the file fits it, otherwise the first one that does is used.
     *
     * @return array<string, string|null>
     */
    private function presetColumns(ImportPreset $preset, CsvParserService $parser, string $path, CsvFormat $readFormat): array
    {
        $columns = app(ImportPresetMapper::class)->mapping($preset, $this->headers);
        $dateColumn = $columns[CsvColumnMapper::FIELD_DATE] ?? null;

        try {
            $options = $dateColumn === null ? [] : app(CsvFormatDetector::class)->dateFormats($parser->sampleValues($path, $dateColumn, $readFormat));
        } catch (Throwable) {
            $options = [];
        }

        if ($options !== [] && ! in_array($columns[CsvFormat::KEY_DATE], $options, true)) {
            $columns[CsvFormat::KEY_DATE] = $options[0];
        }

        $columns[CsvFormat::KEY_DELIMITER] = $readFormat->delimiter;

        return $columns;
    }

    private function storedPath(): ?string
    {
        if ($this->bankImportId === null) {
            return null;
        }

        $bankImport = BankImport::query()->where('user_id', auth()->id())->find($this->bankImportId);

        return $bankImport === null ? null : Storage::disk('local')->path($bankImport->stored_path);
    }

    private function resolveOrCreateAccount(): ?Account
    {
        $user = auth()->user();

        if ($this->accountChoice === 'existing') {
            if ($this->accountId === null) {
                $this->fail('Pick an existing account, or choose to create a new one.');

                return null;
            }

            $account = Account::query()->whereKey($this->accountId)->where('user_id', $user->id)->tracked()->first();

            if ($account === null) {
                $this->fail('That account is not yours.');

                return null;
            }

            if (! $account->acceptsCsvImports()) {
                $this->fail('This account is connected via your bank — CSV imports are not allowed.');

                return null;
            }

            return $account;
        }

        $this->validate([
            'newAccountName' => ['required', 'string', 'max:255'],
            'newAccountLast4' => ['required', 'string', 'size:4'],
            'newAccountBalance' => ['nullable', 'numeric'],
            'newAccountCurrency' => ['nullable', 'string', Rule::in($user->allowedCurrencies())],
        ]);

        return Account::query()->getConnection()->transaction(function () use ($user): Account {
            $account = Account::query()->create([
                'user_id' => $user->id,
                'name' => $this->newAccountName,
                'account_last4' => $this->newAccountLast4,
                'type' => 'transaction',
                'institution' => 'CSV import',
                'currency' => mb_trim($this->newAccountCurrency) !== '' ? $this->newAccountCurrency : $user->base_currency,
                'balance' => (int) round(((float) $this->newAccountBalance) * 100),
                'balance_source' => $this->newAccountBalance === '' ? null : ImportSource::Csv,
                'balance_updated_at' => $this->newAccountBalance === '' ? null : now(),
                'group' => 'day-to-day',
                'status' => 'active',
                'import_source' => ImportSource::Csv,
            ]);

            app(AccountBalanceHistoryBuilder::class)->rebuild($account);

            return $account;
        });
    }
}
