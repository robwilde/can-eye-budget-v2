<?php

declare(strict_types=1);

namespace App\Services\PipelineStages;

use App\Contracts\PipelineStageContract;
use App\DTOs\PipelineContext;
use App\DTOs\StageResult;
use App\Enums\AccountClass;
use App\Enums\PayFrequency;
use App\Enums\SuggestionType;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineAuditEntry;
use App\Models\Transaction;
use Illuminate\Support\Collection;

final readonly class IdentifyPrimaryAccountStage implements PipelineStageContract
{
    private const string STAGE_KEY = 'identify-primary-account';

    private const int MIN_OCCURRENCES = 2;

    private const float MAX_INTERVAL_CV = 0.30;

    private const int SALARY_THRESHOLD_HIGH = 50_000;

    private const int SALARY_THRESHOLD_LOW = 25_000;

    private const array FREQUENCY_RANGES = [
        'weekly' => ['min' => 5, 'max' => 9],
        'fortnightly' => ['min' => 12, 'max' => 16],
        'monthly' => ['min' => 27, 'max' => 35],
    ];

    private const float WEIGHT_INTERVAL = 0.30;

    private const float WEIGHT_AMOUNT = 0.25;

    private const float WEIGHT_COUNT = 0.25;

    private const float WEIGHT_SALARY = 0.20;

    private const float TRANSFER_BONUS_MAX = 0.10;

    private const float TRANSFER_BONUS_PER = 0.02;

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

        $bestOverall = null;

        foreach ($accounts as $account) {
            $candidate = $this->analyzeAccount($account, $context);

            if ($candidate === null) {
                continue;
            }

            if ($bestOverall === null || $candidate['confidence'] > $bestOverall['confidence']) {
                $bestOverall = $candidate;
            }
        }

        if ($bestOverall === null) {
            PipelineAuditEntry::create([
                'pipeline_run_id' => $context->pipelineRun->id,
                'stage' => self::STAGE_KEY,
                'action' => 'no_income_pattern_detected',
                'metadata' => ['accounts_analyzed' => $accounts->count()],
            ]);

            return new StageResult(success: true, stage: self::STAGE_KEY);
        }

        $transferCount = $this->countOutboundTransfers($bestOverall['account'], $context);
        $transferBonus = min(self::TRANSFER_BONUS_MAX, $transferCount * self::TRANSFER_BONUS_PER);
        $finalConfidence = min(1.0, $bestOverall['confidence'] + $transferBonus);

        $suggestion = AnalysisSuggestion::create([
            'pipeline_run_id' => $context->pipelineRun->id,
            'user_id' => $context->user->id,
            'type' => SuggestionType::PrimaryAccount,
            'payload' => [
                'account_id' => $bestOverall['account']->id,
                'account_name' => $bestOverall['account']->name,
                'income_amount' => $bestOverall['median_amount'],
                'income_frequency' => $bestOverall['frequency']->value,
                'income_description' => $bestOverall['description'],
                'confidence_score' => round($finalConfidence, 4),
                'matched_transaction_ids' => $bestOverall['transaction_ids'],
                'outbound_transfer_count' => $transferCount,
            ],
        ]);

        return new StageResult(
            success: true,
            stage: self::STAGE_KEY,
            suggestionIds: [$suggestion->id],
        );
    }

    /**
     * @return array{account: Account, confidence: float, median_amount: int, frequency: PayFrequency, description: string, transaction_ids: list<int>}|null
     */
    private function analyzeAccount(Account $account, PipelineContext $context): ?array
    {
        $credits = Transaction::query()
            ->where('account_id', $account->id)
            ->where('user_id', $context->user->id)
            ->where('direction', TransactionDirection::Credit)
            ->where('source', TransactionSource::Basiq)
            ->whereNull('transfer_pair_id')
            ->current()
            ->orderBy('post_date')
            ->get();

        if ($credits->count() < self::MIN_OCCURRENCES) {
            return null;
        }

        $grouped = $credits->groupBy(fn (Transaction $t) => $this->normalizeDescription($t));

        $bestCandidate = null;

        foreach ($grouped as $description => $transactions) {
            if ($transactions->count() < self::MIN_OCCURRENCES) {
                continue;
            }

            $result = $this->analyzeGroup($account, (string) $description, $transactions);

            if ($result === null) {
                continue;
            }

            if ($bestCandidate === null || $result['confidence'] > $bestCandidate['confidence']) {
                $bestCandidate = $result;
            }
        }

        return $bestCandidate;
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array{account: Account, confidence: float, median_amount: int, frequency: PayFrequency, description: string, transaction_ids: list<int>}|null
     */
    private function analyzeGroup(Account $account, string $description, Collection $transactions): ?array
    {
        $sorted = $transactions->sortBy('post_date')->values();
        $intervals = $this->calculateIntervals($sorted);

        if ($intervals === []) {
            return null;
        }

        $medianInterval = $this->median($intervals);
        $frequency = $this->mapFrequency($medianInterval);

        if ($frequency === null) {
            return null;
        }

        $intervalCV = $this->coefficientOfVariation($intervals);

        if ($intervalCV > self::MAX_INTERVAL_CV) {
            return null;
        }

        $amounts = $sorted->pluck('amount')->map(fn ($a) => abs((int) $a))->all();
        $medianAmount = $this->median($amounts);
        $amountCV = $this->coefficientOfVariation($amounts);

        $intervalScore = max(0.0, 1.0 - $intervalCV * 3.33);
        $amountScore = max(0.0, 1.0 - $amountCV / 0.10);
        $countScore = min(1.0, log($sorted->count(), 2) / log(8, 2));
        $salaryScore = $this->salaryScore($medianAmount);

        $confidence = (self::WEIGHT_INTERVAL * $intervalScore)
            + (self::WEIGHT_AMOUNT * $amountScore)
            + (self::WEIGHT_COUNT * $countScore)
            + (self::WEIGHT_SALARY * $salaryScore);

        return [
            'account' => $account,
            'confidence' => round($confidence, 4),
            'median_amount' => (int) round($medianAmount),
            'frequency' => $frequency,
            'description' => $description,
            'transaction_ids' => $sorted->pluck('id')->all(),
        ];
    }

    /**
     * @param  Collection<int, Transaction>  $sorted
     * @return list<float>
     */
    private function calculateIntervals(Collection $sorted): array
    {
        $intervals = [];

        for ($i = 1; $i < $sorted->count(); $i++) {
            $intervals[] = (float) $sorted[$i - 1]->post_date->diffInDays($sorted[$i]->post_date);
        }

        return $intervals;
    }

    private function mapFrequency(float $medianInterval): ?PayFrequency
    {
        foreach (self::FREQUENCY_RANGES as $freq => $range) {
            if ($medianInterval >= $range['min'] && $medianInterval <= $range['max']) {
                return PayFrequency::from($freq);
            }
        }

        return null;
    }

    /**
     * @param  list<float|int>  $values
     */
    private function coefficientOfVariation(array $values): float
    {
        $count = count($values);

        if ($count < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;

        if ($mean === 0.0) {
            return 0.0;
        }

        $sumSquaredDiffs = 0.0;

        foreach ($values as $v) {
            $sumSquaredDiffs += ($v - $mean) ** 2;
        }

        $stdDev = sqrt($sumSquaredDiffs / $count);

        return $stdDev / abs($mean);
    }

    /**
     * @param  list<float|int>  $values
     */
    private function median(array $values): float
    {
        $sorted = $values;
        sort($sorted);

        $count = count($sorted);
        $mid = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($sorted[$mid - 1] + $sorted[$mid]) / 2.0;
        }

        return (float) $sorted[$mid];
    }

    private function salaryScore(float $medianAmount): float
    {
        if ($medianAmount >= self::SALARY_THRESHOLD_HIGH) {
            return 1.0;
        }

        if ($medianAmount >= self::SALARY_THRESHOLD_LOW) {
            return 0.5;
        }

        return 0.0;
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

    private function normalizeDescription(Transaction $transaction): string
    {
        if ($transaction->merchant_name !== null && $transaction->merchant_name !== '') {
            return mb_strtoupper(mb_trim($transaction->merchant_name));
        }

        if ($transaction->clean_description !== null && $transaction->clean_description !== '') {
            return mb_strtoupper(mb_trim($transaction->clean_description));
        }

        $normalized = mb_strtoupper(mb_trim($transaction->description));

        return (string) preg_replace('/\s+/', ' ', $normalized);
    }
}
