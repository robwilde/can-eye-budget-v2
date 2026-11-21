<?php

declare(strict_types=1);

namespace App\Services\CsvImport;

use App\Enums\BankImportStatus;
use App\Exceptions\BankImportRevertRefused;
use App\Models\BankImport;
use App\Models\Transaction;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\Transactions\TransactionDeleter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Undoes a completed CSV import: soft-deletes exactly the rows it created or restored, then
 * returns it to previewing so it can be confirmed again from its stored file. Rows it only
 * matched as duplicates, and rows an earlier import or the user made, are never touched. A row
 * a bank-feed sync has since adopted belongs to the feed and is kept.
 */
final readonly class BankImportReverter
{
    public function __construct(
        private TransactionDeleter $deleter,
        private AccountBalanceHistoryBuilder $history,
    ) {}

    /**
     * Everything happens in one transaction, so a failure leaves the import and its rows as
     * they were and the revert can be tried again.
     *
     * @throws BankImportRevertRefused
     * @throws Throwable
     */
    public function revert(BankImport $bankImport): BankImport
    {
        $rows = fn (): Builder => Transaction::query()
            ->where('user_id', $bankImport->user_id)
            ->where(fn (Builder $query): Builder => $query
                ->where('bank_import_id', $bankImport->id)
                ->orWhere('restored_by_bank_import_id', $bankImport->id));

        $accountIds = $this->history->accountIdsOfRows($rows());

        return DB::transaction(function () use ($bankImport, $rows, $accountIds): BankImport {
            $locked = BankImport::query()->where('user_id', $bankImport->user_id)->whereKey($bankImport->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== BankImportStatus::Completed) {
                throw new BankImportRevertRefused(__('Only a completed import can be reverted.'));
            }

            $this->history->lockAccounts(...$accountIds);
            $versionIds = $rows()->whereNull('redbark_id')->whereNotNull('parent_transaction_id')->pluck('id')->all();

            // An edit leaves a live older version beside the current one, both with the row's
            // hash; soft-deleting both in the same second would break the unique
            // (account, hash, deleted_at) index. The root keeps the hash so a re-confirm restores it.
            Transaction::query()->where('user_id', $locked->user_id)->whereIn('id', $versionIds)->update(['csv_hash' => null]);

            $this->deleter->delete($rows()->whereNull('redbark_id'));

            $locked->update([
                'status' => BankImportStatus::Previewing,
                'started_at' => null,
                'completed_at' => null,
                'row_count' => 0,
                'imported_count' => 0,
                'skipped_count' => 0,
                'restored_count' => 0,
                'row_errors' => null,
                'unresolved_categories' => null,
                'error_summary' => null,
            ]);

            return $locked;
        });
    }
}
