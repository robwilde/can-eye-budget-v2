<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BankImport;
use App\Models\Document;
use App\Models\Statement;
use App\Models\StatementReconciliation;
use App\Models\TransactionAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final readonly class UserDataResetter
{
    /**
     * Every table that carries the owner in a user_id column and is cleared by a reset, in the
     * order they are deleted. Tables scoped through a parent (splits, occurrences, rows) go with
     * their parent through the foreign key cascade. UserDataResetterCoverageTest pins this list.
     *
     * @var list<string>
     */
    public const array TABLES = [
        'transactions',
        'planned_transactions',
        'pdf_imports',
        'statement_reconciliations',
        'statements',
        'bank_imports',
        'accounts',
        'analysis_suggestions',
        'bill_alert_sends',
        'bnpl_orders',
        'budgets',
        'budget_plans',
        'goals',
        'insights',
        'merchant_aliases',
        'merchant_brands',
        'merchants',
        'payees',
        'pipeline_runs',
        'planned_match_rejections',
        'redbark_feeds',
        'redbark_sync_logs',
        'rule_email_deliveries',
        'tags',
        'transaction_emails',
        'transfer_rules',
        'user_category_budget_tags',
        'user_rules',
        'user_rule_groups',
        'categories',
        'category_groups',
        'data_exports',
        'documents',
    ];

    /**
     * User-owned tables a reset leaves alone: the audit trail that records it, the login sessions,
     * the personal API keys and the connected mailbox, which is a provider setting rather than financial data.
     *
     * @var list<string>
     */
    public const array KEPT_TABLES = [
        'audit_events',
        'sessions',
        'api_keys',
        'gmail_credentials',
    ];

    public function __construct(
        private UserCategoryProvisioner $categories,
        private MonthEndBalanceRuleProvisioner $balanceRule,
    ) {}

    /**
     * Deletes everything the user's finances created, in one transaction, and gives them back
     * the default categories and month-end rule a new account starts with. The login, two-factor
     * setup, preferences and connected mailbox stay; the pay cycle and primary account go, because
     * they describe data that no longer exists. Stored files go only once the delete has committed.
     */
    public function reset(User $user): void
    {
        $userId = $user->id;
        $attachments = TransactionAttachment::query()->forUser($user)->get(['disk', 'path']);
        $statementPaths = Statement::query()->forUser($user)->pluck('stored_path');
        $localPaths = [
            ...Document::query()->forUser($userId)->pluck('stored_path')->all(),
            ...BankImport::query()->where('user_id', $userId)->pluck('stored_path')->all(),
            ...StatementReconciliation::query()->where('user_id', $userId)->pluck('stored_path')->all(),
        ];

        $user->getConnection()->transaction(function () use ($user, $userId, $attachments, $statementPaths, $localPaths): void {
            foreach (self::TABLES as $table) {
                DB::table($table)->where('user_id', $userId)->delete();
            }

            $user->forceFill([
                'pay_amount' => null,
                'pay_frequency' => null,
                'next_pay_date' => null,
                'primary_account_id' => null,
                'vector_store_id' => null,
            ])->save();

            $this->categories->provision($user);
            $this->balanceRule->provisionForUser($userId);

            TransactionAttachment::remember($user, $attachments);
            Statement::remember($user, $statementPaths);
            TransactionAttachment::purgeRemembered($user);
            Statement::purgeRemembered($user);

            $user->getConnection()->afterCommit(static function () use ($userId, $localPaths): void {
                Storage::disk('local')->delete($localPaths);
                Storage::disk('local')->deleteDirectory('exports/'.$userId);
            });
        });
    }
}
