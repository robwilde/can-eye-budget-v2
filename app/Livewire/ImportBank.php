<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\BankImportStatus;
use App\Enums\ImportSource;
use App\Jobs\ImportCsvTransactionsJob;
use App\Models\Account;
use App\Models\BankImport;
use App\Services\CsvImport\CsvColumnMapper;
use App\Services\CsvImport\CsvParserService;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use League\Csv\Exception;
use League\Csv\SyntaxError;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

final class ImportBank extends Component
{
    use WithFileUploads;

    public int $step = 1;

    #[Validate('nullable|file|mimes:csv,txt|max:10240')]
    public ?TemporaryUploadedFile $file = null;

    public ?int $accountId = null;

    public string $accountChoice = 'existing';

    #[Validate('nullable|string|max:255')]
    public string $newAccountName = '';

    #[Validate('nullable|string|size:4')]
    public string $newAccountLast4 = '';

    /** @var list<string> */
    public array $headers = [];

    /** @var array<string, string|null> */
    public array $mapping = [];

    public ?int $bankImportId = null;

    public ?string $errorMessage = null;

    /**
     * @throws SyntaxError
     * @throws Exception
     */
    public function uploadAndDetectHeaders(CsvParserService $parser, CsvColumnMapper $mapper): void
    {
        $this->errorMessage = null;

        $this->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $account = $this->resolveOrCreateAccount();

        if ($account === null) {
            return;
        }

        $this->accountId = $account->id;

        $storedPath = $this->file->store('bank-imports', 'local');
        $absolutePath = Storage::disk('local')->path($storedPath);

        $this->headers = $parser->headers($absolutePath);

        $existingMapping = $account->column_mapping ?? [];
        $this->mapping = $existingMapping !== []
            ? array_merge($mapper->suggest($this->headers), $existingMapping)
            : $mapper->suggest($this->headers);

        $this->bankImportId = BankImport::query()->create([
            'user_id' => auth()->id(),
            'account_id' => $account->id,
            'original_filename' => $this->file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'status' => BankImportStatus::Previewing,
            'column_mapping' => $this->mapping,
        ])->id;

        $this->step = 2;
    }

    public function refreshPreview(): void
    {
        // Preview/summary are recomputed in render() — this hook just triggers a re-render.
    }

    public function confirmImport(): void
    {
        $this->errorMessage = null;

        if ($this->bankImportId === null || $this->accountId === null) {
            $this->errorMessage = 'No import in progress.';

            return;
        }

        $account = Account::query()
            ->whereKey($this->accountId)
            ->where('user_id', auth()->id())
            ->first();

        if ($account === null || ! $account->acceptsCsvImports()) {
            $this->addError('account_id', 'You cannot import a CSV into an account connected via your bank.');

            return;
        }

        $this->validate([
            'mapping' => ['required', 'array'],
            'mapping.date' => ['required', 'string'],
            'mapping.amount' => ['nullable', 'string', 'required_without_all:mapping.debit,mapping.credit'],
            'mapping.debit' => ['nullable', 'string'],
            'mapping.credit' => ['nullable', 'string'],
        ]);

        if (! empty($this->mapping['amount']) && (! empty($this->mapping['debit']) || ! empty($this->mapping['credit']))) {
            $this->addError('mapping.amount', 'Map either a signed amount column OR debit/credit columns, not both.');

            return;
        }

        $bankImport = BankImport::query()
            ->where('user_id', auth()->id())
            ->findOrFail($this->bankImportId);

        $bankImport->update([
            'status' => BankImportStatus::Pending,
            'column_mapping' => $this->mapping,
        ]);

        Account::query()
            ->whereKey($this->accountId)
            ->update(['column_mapping' => $this->mapping]);

        ImportCsvTransactionsJob::dispatch($bankImport);

        $this->step = 3;
    }

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
            'headers',
            'mapping',
            'bankImportId',
            'errorMessage',
        ]);

        $this->step = 1;
    }

    public function render(CsvParserService $parser): View
    {
        $userAccounts = auth()->user()->accounts()->visible()->get();
        $bankImport = $this->bankImportId !== null
            ? BankImport::query()->where('user_id', auth()->id())->find($this->bankImportId)
            : null;

        $previewRows = [];
        $summary = null;

        if ($this->step >= 2 && $bankImport !== null) {
            $absolutePath = Storage::disk('local')->path($bankImport->stored_path);

            try {
                $previewRows = $parser->preview($absolutePath, $this->mapping, limit: 10);
                $summary = $parser->summarize($absolutePath, $this->mapping);
            } catch (Throwable) {
                // ignore preview failures — user is still adjusting the mapping
            }
        }

        return view('livewire.import-bank', [
            'accounts' => $userAccounts,
            'bankImport' => $bankImport,
            'previewRows' => $previewRows,
            'summary' => $summary,
            'fields' => [
                CsvColumnMapper::FIELD_DATE => 'Date',
                CsvColumnMapper::FIELD_DESCRIPTION => 'Description',
                CsvColumnMapper::FIELD_AMOUNT => 'Signed amount',
                CsvColumnMapper::FIELD_DEBIT => 'Debit (out)',
                CsvColumnMapper::FIELD_CREDIT => 'Credit (in)',
                CsvColumnMapper::FIELD_BALANCE => 'Balance',
            ],
        ]);
    }

    private function resolveOrCreateAccount(): ?Account
    {
        $user = auth()->user();

        if ($this->accountChoice === 'existing') {
            if ($this->accountId === null) {
                $this->errorMessage = 'Pick an existing account, or choose to create a new one.';

                return null;
            }

            $account = Account::query()->whereKey($this->accountId)->where('user_id', $user->id)->first();

            if ($account === null) {
                $this->errorMessage = 'That account is not yours.';

                return null;
            }

            if (! $account->acceptsCsvImports()) {
                $this->errorMessage = 'This account is connected via your bank — CSV imports are not allowed.';

                return null;
            }

            return $account;
        }

        $this->validate([
            'newAccountName' => ['required', 'string', 'max:255'],
            'newAccountLast4' => ['required', 'string', 'size:4'],
        ]);

        return Account::query()->create([
            'user_id' => $user->id,
            'name' => $this->newAccountName,
            'account_last4' => $this->newAccountLast4,
            'type' => 'transaction',
            'institution' => 'CSV import',
            'currency' => 'AUD',
            'balance' => 0,
            'group' => 'day-to-day',
            'status' => 'active',
            'import_source' => ImportSource::Csv,
        ]);
    }
}
