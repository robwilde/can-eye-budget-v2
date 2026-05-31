<?php

declare(strict_types=1);

namespace App\Services\PipelineStages;

use App\Contracts\PipelineStageContract;
use App\DTOs\IncomePattern;
use App\DTOs\PipelineContext;
use App\DTOs\StageResult;
use App\Enums\AccountClass;
use App\Enums\SuggestionType;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineAuditEntry;
use App\Models\Transaction;
use App\Services\IncomePatternDetector;
use App\Services\SuggestionApplier;
use Illuminate\Support\Collection;

final readonly class IdentifyPrimaryAccountStage implements PipelineStageContract
{
    private const string STAGE_KEY = 'identify-primary-account';

    private const float TRANSFER_BONUS_MAX = 0.10;

    private const float TRANSFER_BONUS_PER = 0.02;

    public function __construct(
        private IncomePatternDetector $detector,
        private SuggestionApplier $applier,
    ) {}

    public function key(): string
    {
        return self::STAGE_KEY;
    }

    public function label(): string
    {
        return 'Identify Primary Account';
    }

    public function shouldRun(PipelineContext $context): bool
    {
        return $context->isFirstSync && $context->user->primary_account_id === null;
    }

    public function execute(PipelineContext $context): StageResult
    {
        $accounts = $context->user->accounts()
            ->active()
            ->whereIn('type', [AccountClass::Transaction, AccountClass::Savings])
            ->get();

        if ($accounts->isEmpty()) {
            return new StageResult(success: true, stage: self::STAGE_KEY);
        }

        $suggestionIds = [];

        $candidateCount = Transaction::query()
            ->whereIn('account_id', $accounts->pluck('id'))
            ->where('direction', TransactionDirection::Credit)
            ->whereIn('source', TransactionSource::forAnalysis())
            ->whereNull('transfer_pair_id')
            ->current()
            ->count();

        if ($candidateCount === 0) {
            $this->audit($context, 'no_transactions_to_analyze', ['accounts_analyzed' => $accounts->count()]);
        } else {
            $best = $this->detectBestAccount($accounts, $context);

            if ($best === null) {
                $this->audit($context, 'no_income_pattern_detected', ['accounts_analyzed' => $accounts->count()]);
            } else {
                $suggestion = AnalysisSuggestion::create([
                    'pipeline_run_id' => $context->pipelineRun->id,
                    'user_id' => $context->user->id,
                    'type' => SuggestionType::PrimaryAccount,
                    'payload' => [
                        'account_id' => $best['account']->id,
                        'account_name' => $best['account']->name,
                        'income_amount' => $best['pattern']->amount,
                        'income_frequency' => $best['pattern']->frequency->value,
                        'income_description' => $best['pattern']->description,
                        'confidence_score' => round($best['confidence'], 4),
                        'matched_transaction_ids' => $best['pattern']->transactionIds,
                        'outbound_transfer_count' => $best['transfer_count'],
                    ],
                ]);

                $suggestionIds[] = $suggestion->id;

                if ($best['confidence'] >= IncomePatternDetector::AUTO_APPLY_CONFIDENCE) {
                    $this->applier->applyPrimaryAccount($suggestion, $context->user);
                    $this->audit($context, 'auto_applied', [
                        'account_id' => $best['account']->id,
                        'confidence' => round($best['confidence'], 4),
                    ]);
                }
            }
        }

        // Guaranteed fallback: if detection set no primary and there is exactly
        // one eligible account, that account is unambiguously the primary one.
        // This is the "first import is your primary account" path.
        if ($accounts->count() === 1 && $context->user->primary_account_id === null) {
            $singleAccount = $accounts->first();
            $context->user->update(['primary_account_id' => $singleAccount->id]);
            $this->audit($context, 'primary_account_fallback_applied', ['account_id' => $singleAccount->id]);
        }

        return new StageResult(
            success: true,
            stage: self::STAGE_KEY,
            suggestionIds: $suggestionIds,
        );
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return array{account: Account, pattern: IncomePattern, confidence: float, transfer_count: int}|null
     */
    private function detectBestAccount(Collection $accounts, PipelineContext $context): ?array
    {
        $best = null;

        foreach ($accounts as $account) {
            $pattern = $this->detector->detectForAccount($account);

            if ($pattern === null) {
                continue;
            }

            $transferCount = $this->countOutboundTransfers($account, $context);
            $transferBonus = min(self::TRANSFER_BONUS_MAX, $transferCount * self::TRANSFER_BONUS_PER);
            $confidence = min(1.0, $pattern->confidence + $transferBonus);

            if ($best === null || $confidence > $best['confidence']) {
                $best = [
                    'account' => $account,
                    'pattern' => $pattern,
                    'confidence' => $confidence,
                    'transfer_count' => $transferCount,
                ];
            }
        }

        return $best;
    }

    private function countOutboundTransfers(Account $account, PipelineContext $context): int
    {
        $userAccountIds = $context->user->accounts()
            ->where('id', '!=', $account->id)
            ->pluck('id');

        return Transaction::query()
            ->where('account_id', $account->id)
            ->where('user_id', $context->user->id)
            ->where('direction', TransactionDirection::Debit)
            ->whereNotNull('transfer_pair_id')
            ->whereHas('transferPair', fn ($q) => $q->whereIn('account_id', $userAccountIds))
            ->current()
            ->count();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(PipelineContext $context, string $action, array $metadata = []): void
    {
        PipelineAuditEntry::create([
            'pipeline_run_id' => $context->pipelineRun->id,
            'stage' => self::STAGE_KEY,
            'action' => $action,
            'metadata' => $metadata,
        ]);
    }
}
