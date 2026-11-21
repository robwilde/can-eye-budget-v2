<?php

declare(strict_types=1);

namespace App\Services\Statement;

use App\DTOs\PdfImportPlan;
use App\Enums\PdfImportStatus;
use App\Enums\StatementLineKind;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Exceptions\Statement\PdfImportRefused;
use App\Models\Account;
use App\Models\PdfImport;
use App\Models\PdfImportLine;
use App\Models\Transaction;
use App\Services\CsvImport\ParsedTransactionDto;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\TransactionIngestor;
use App\Services\Transactions\TransactionDeleter;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Turns a reviewed PDF import into ledger rows and back. Publishing matches the lines against
 * the account's transactions again (a sync may have landed since the review), creates the
 * lines that are not recorded yet and marks the matched transactions as reconciled by the
 * statement. Reverting removes exactly the rows the import created and its reconciled marks.
 */
final readonly class PdfImportPublisher
{
    public const int IDLE_HOURS_BEFORE_FAILED = 1;

    public function __construct(
        private StatementLineMatcher $matcher,
        private TransactionIngestor $ingestor,
        private AccountBalanceHistoryBuilder $history,
        private TransactionDeleter $deleter,
    ) {}

    public function plan(PdfImport $import, Account $account): PdfImportPlan
    {
        $lines = $import->lines()->orderBy('id')->get();
        $readable = $lines->filter(fn (PdfImportLine $line): bool => $line->line_date !== null && ! $line->date_unreadable)->values();
        $waiting = $lines->reject(fn (PdfImportLine $line): bool => $line->line_date !== null && ! $line->date_unreadable)->pluck('id')->all();

        if ($readable->isEmpty()) {
            return new PdfImportPlan([], [], array_values($waiting), 0);
        }

        $entries = $this->matcher->match(
            $account,
            $readable->map(fn (PdfImportLine $line): ParsedTransactionDto => $this->parsed($line))->values(),
            $this->period($import, $readable),
        );

        $matched = [];
        $statementOnly = [];
        $feedOnly = 0;

        foreach ($entries as $entry) {
            $lineId = $entry->line === null ? null : $this->lineId($entry->line);

            match ($entry->kind) {
                StatementLineKind::Matched => $matched[$lineId] = $entry->transaction,
                StatementLineKind::StatementOnly => $statementOnly[] = $lineId,
                StatementLineKind::FeedOnly => $feedOnly++,
            };
        }

        return new PdfImportPlan($matched, $statementOnly, array_values($waiting), $feedOnly);
    }

    /**
     * @throws PdfImportRefused
     */
    public function publish(PdfImport $import, Account $account): PdfImport
    {
        return DB::transaction(function () use ($import, $account): PdfImport {
            $locked = $this->lock($import);

            if (! in_array($locked->status, [PdfImportStatus::Ready, PdfImportStatus::Reverted], true)) {
                throw new PdfImportRefused(__('This import cannot be published.'));
            }

            if (! $this->isTrackedAccountOf($locked, $account) || $locked->statement_id === null) {
                throw new PdfImportRefused(__('Choose one of your own accounts.'));
            }

            $this->history->lockAccounts($account->id);

            $plan = $this->plan($locked, $account);
            $lines = $locked->lines()->get()->keyBy('id');

            foreach ($plan->matched as $lineId => $transaction) {
                Transaction::query()->where('user_id', $locked->user_id)->whereKey($transaction->id)->whereNull('reconciled_statement_id')->update(['reconciled_statement_id' => $locked->statement_id]);
                $lines[$lineId]->update(['kind' => StatementLineKind::Matched, 'matched_transaction_id' => $transaction->id]);
            }

            $createdRows = [];

            foreach ($plan->statementOnly as $lineId) {
                $created = $this->ingestor->ingest($this->transactionFor($locked, $account, $lines[$lineId]), rebuildHistory: false);
                $createdRows[] = $created;
                $lines[$lineId]->update(['kind' => StatementLineKind::Matched, 'matched_transaction_id' => $created->id]);
            }

            if ($createdRows !== []) {
                $this->history->rebuildForTransactions(...$createdRows);
            }

            $locked->update([
                'status' => PdfImportStatus::Published,
                'account_id' => $account->id,
                'summary' => [
                    ...($locked->summary ?? []),
                    'found' => $lines->count(),
                    'already_recorded' => count($plan->matched),
                    'imported' => count($plan->statementOnly),
                    'waiting' => count($plan->waiting),
                ],
                'completed_at' => now(),
            ]);

            return $locked;
        });
    }

    /**
     * @throws PdfImportRefused
     */
    public function revert(PdfImport $import): PdfImport
    {
        $createdRows = fn (): Builder => Transaction::query()->where('user_id', $import->user_id)->where('pdf_import_id', $import->id);

        $accountIds = $this->history->accountIdsOfRows($createdRows());

        return DB::transaction(function () use ($import, $createdRows, $accountIds): PdfImport {
            $locked = $this->lock($import);

            if ($locked->status !== PdfImportStatus::Published) {
                throw new PdfImportRefused(__('Only a published import can be reverted.'));
            }

            $this->history->lockAccounts(...$accountIds);

            $adopted = $createdRows()->whereNotNull('redbark_id')->pluck('id')->all();
            $deletedIds = $createdRows()->whereNull('redbark_id')->pluck('id')->all();

            $reconciled = $locked->lines()->whereNotNull('matched_transaction_id')->pluck('matched_transaction_id')
                ->reject(fn (int $id): bool => in_array($id, [...$deletedIds, ...$adopted], true))
                ->flatMap(fn (int $id): array => array_filter([$id, Transaction::findCurrentVersion($id, $locked->user_id)?->id]))
                ->all();

            $this->deleter->delete($createdRows()->whereNull('redbark_id'));

            Transaction::query()
                ->where('user_id', $locked->user_id)
                ->where('reconciled_statement_id', $locked->statement_id)
                ->whereIn('id', [...$reconciled, ...$deletedIds, ...$adopted])
                ->update(['reconciled_statement_id' => null]);

            Transaction::query()->where('user_id', $locked->user_id)->whereIn('id', $adopted)->update(['pdf_import_id' => null]);

            $locked->lines()->update(['kind' => StatementLineKind::StatementOnly, 'matched_transaction_id' => null]);
            $locked->update(['status' => PdfImportStatus::Reverted]);

            return $locked;
        });
    }

    /**
     * @throws PdfImportRefused
     */
    public function changeAccount(PdfImport $import, Account $account): PdfImport
    {
        return DB::transaction(function () use ($import, $account): PdfImport {
            $locked = $this->lock($import);

            if ($locked->status === PdfImportStatus::Published) {
                throw new PdfImportRefused(__('This import is published, revert it first.'));
            }

            if (! in_array($locked->status, [PdfImportStatus::Ready, PdfImportStatus::Reverted], true)) {
                throw new PdfImportRefused(__('This import is not ready yet.'));
            }

            if (! $this->isTrackedAccountOf($locked, $account)) {
                throw new PdfImportRefused(__('Choose one of your own accounts.'));
            }

            $locked->lines()->update(['kind' => StatementLineKind::StatementOnly, 'matched_transaction_id' => null]);
            $locked->update(['account_id' => $account->id]);

            return $locked;
        });
    }

    /**
     * An import that is still pending or processing after an hour is stuck.
     *
     * @throws PdfImportRefused
     */
    public function markFailed(PdfImport $import): PdfImport
    {
        return DB::transaction(function () use ($import): PdfImport {
            $locked = $this->lock($import);

            $stuck = in_array($locked->status, [PdfImportStatus::Pending, PdfImportStatus::Processing], true)
                && $locked->updated_at->lte(now()->subHours(self::IDLE_HOURS_BEFORE_FAILED));

            if (! $stuck) {
                throw new PdfImportRefused(__('This import is still running.'));
            }

            $locked->update([
                'status' => PdfImportStatus::Failed,
                'failure_message' => __('Marked as failed after being idle for over an hour.'),
                'completed_at' => now(),
            ]);

            return $locked;
        });
    }

    private function isTrackedAccountOf(PdfImport $import, Account $account): bool
    {
        return Account::query()->where('user_id', $import->user_id)->tracked()->whereKey($account->id)->exists();
    }

    private function lock(PdfImport $import): PdfImport
    {
        return PdfImport::query()->forUser($import->user_id)->whereKey($import->id)->lockForUpdate()->firstOrFail();
    }

    private function transactionFor(PdfImport $import, Account $account, PdfImportLine $line): Transaction
    {
        return new Transaction([
            'user_id' => $import->user_id,
            'account_id' => $account->id,
            'amount' => $line->amount,
            'direction' => $line->direction,
            'description' => $line->description,
            'post_date' => $line->line_date,
            'transaction_date' => $line->line_date,
            'status' => TransactionStatus::Posted,
            'source' => TransactionSource::Pdf,
            'pdf_import_id' => $import->id,
            'reconciled_statement_id' => $import->statement_id,
        ]);
    }

    private function parsed(PdfImportLine $line): ParsedTransactionDto
    {
        return new ParsedTransactionDto(
            postDate: CarbonImmutable::parse($line->line_date),
            amount: $line->amount,
            direction: $line->direction,
            description: $line->description,
            balance: null,
            rawRow: [],
            csvHash: 'pdf-line-'.$line->id,
        );
    }

    private function lineId(ParsedTransactionDto $line): int
    {
        return (int) mb_substr($line->csvHash, mb_strlen('pdf-line-'));
    }

    /**
     * The statement's own period, widened to every readable line so none falls outside it.
     *
     * @param  \Illuminate\Support\Collection<int, PdfImportLine>  $readable
     */
    private function period(PdfImport $import, \Illuminate\Support\Collection $readable): CarbonPeriod
    {
        $first = CarbonImmutable::parse($readable->min('line_date'));
        $last = CarbonImmutable::parse($readable->max('line_date'));
        $statement = $import->statement;

        return CarbonPeriod::create(
            $statement?->period_start === null ? $first : $statement->period_start->min($first),
            $statement?->period_end === null ? $last : $statement->period_end->max($last),
        );
    }
}
