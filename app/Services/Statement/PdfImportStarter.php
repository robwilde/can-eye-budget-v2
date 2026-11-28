<?php

declare(strict_types=1);

namespace App\Services\Statement;

use App\Enums\PdfImportStatus;
use App\Enums\StatementFileType;
use App\Exceptions\Statement\PdfImportRefused;
use App\Jobs\ExtractStatementPdfJob;
use App\Models\Account;
use App\Models\PdfImport;
use App\Models\Statement;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Starts the AI extraction of a statement PDF. The PDF is filed in the vault first and an
 * existing statement with the same content is reused, so nothing is stored or extracted twice.
 */
final class PdfImportStarter
{
    public function __construct(private readonly StatementUploadService $uploads) {}

    /**
     * @throws PdfImportRefused with a message safe to show the user
     */
    public function start(User $user, UploadedFile $file, ?Account $account = null, ?string $dateFormat = null): PdfImport
    {
        if (! config('budget.pdf_import.enabled')) {
            throw new PdfImportRefused(__('PDF import is not enabled.'));
        }

        if ($account !== null && $account->user_id !== $user->id) {
            throw new PdfImportRefused(__('Choose one of your own accounts.'));
        }

        if ($dateFormat !== null && ! in_array($dateFormat, explode(',', AnthropicStatementPdfExtractor::DATE_FORMATS), true)) {
            throw new PdfImportRefused(__('That date format is not supported.'));
        }

        $statement = $this->statementFor($user, $file, $account);
        $accountId = $account->id ?? $statement->account_id;

        $created = false;

        $import = DB::transaction(function () use ($user, $statement, $accountId, $dateFormat, &$created): PdfImport {
            Statement::query()->whereKey($statement->id)->lockForUpdate()->first();

            $existing = PdfImport::query()
                ->forUser($user)
                ->where('statement_id', $statement->id)
                ->where('status', '!=', PdfImportStatus::Failed)
                ->where('account_id', $accountId)
                ->where('date_format', $dateFormat)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $created = true;

            return PdfImport::query()->create([
                'user_id' => $user->id,
                'statement_id' => $statement->id,
                'account_id' => $accountId,
                'date_format' => $dateFormat,
            ]);
        });

        if ($created) {
            ExtractStatementPdfJob::dispatch($import);
        }

        return $import;
    }

    private function statementFor(User $user, UploadedFile $file, ?Account $account): Statement
    {
        if (StatementFileType::tryFrom(mb_strtolower($file->getClientOriginalExtension())) !== StatementFileType::Pdf) {
            throw new PdfImportRefused(__('Only PDF files can be imported.'));
        }

        $path = $file->getRealPath();
        $hash = $path === false ? null : hash_file('sha256', $path);

        if ($hash === false || $hash === null) {
            throw new PdfImportRefused(__('The file could not be read.'));
        }

        $statement = Statement::query()->where('user_id', $user->id)->where('content_hash', $hash)->first();

        if ($statement === null) {
            $result = $this->uploads->upload($user, [$file], $account);

            if ($result->errorMessage() !== null) {
                throw new PdfImportRefused($result->errorMessage());
            }

            $statement = $result->created[0] ?? Statement::query()->where('user_id', $user->id)->where('content_hash', $hash)->first();
        }

        if ($statement === null || $statement->file_type !== StatementFileType::Pdf) {
            throw new PdfImportRefused(__('Only PDF files can be imported.'));
        }

        return $statement;
    }
}
