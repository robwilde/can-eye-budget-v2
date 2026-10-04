<?php

declare(strict_types=1);

namespace App\Services\PipelineStages;

use App\Contracts\PipelineStageContract;
use App\DTOs\PipelineContext;
use App\DTOs\StageResult;
use App\Enums\PipelineTrigger;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Models\Category;
use App\Models\PipelineAuditEntry;
use App\Models\Transaction;
use App\Models\UserRule;
use App\Services\CategoryRuleMiner;
use App\Services\IncomePatternDetector;
use App\Services\RuleEvaluator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class RuleMiningStage implements PipelineStageContract
{
    private const string STAGE_KEY = 'rule-mining';

    private const string SALARY_CATEGORY_PATH = 'Income / Salary';

    private const int MIN_SIGNIFICANT_LENGTH = 4;

    /** @var list<string> */
    private const array GENERIC_TOKENS = [
        'DIRECT', 'CREDIT', 'DEBIT', 'DEPOSIT', 'TRANSFER', 'PAYMENT', 'EFT', 'BPAY', 'OSKO', 'NPP', 'FROM', 'THE',
        'PTY', 'LTD', 'LIMITED', 'INC', 'INCORPORATED', 'CO', 'CORP', 'CORPORATION', 'COMPANY', 'GROUP', 'LLC', 'PLC',
        'PAYROLL', 'SALARY', 'WAGES', 'WAGE', 'PAY',
    ];

    public function __construct(
        private CategoryRuleMiner $miner,
        private IncomePatternDetector $incomeDetector,
        private RuleEvaluator $evaluator,
    ) {}

    public function key(): string
    {
        return self::STAGE_KEY;
    }

    public function label(): string
    {
        return 'Rule Mining';
    }

    public function shouldRun(PipelineContext $context): bool
    {
        return $context->pipelineRun->trigger === PipelineTrigger::Sync
            && ! $this->hasMined($context)
            && ($context->isFirstSync || $this->hasFailedAttempt($context));
    }

    public function execute(PipelineContext $context): StageResult
    {
        $imported = Transaction::query()
            ->where('user_id', $context->user->id)
            ->whereIn('source', TransactionSource::forAnalysis())
            ->current()
            ->get();

        $result = $this->miner->mine(
            $context->user,
            [],
            $this->incomeSeeds($context),
            fn (array $entry): bool => $entry['source'] === 'mined' || $this->matchesAny($entry, $imported),
        );

        DB::transaction(function () use ($context, $result): void {
            $rules = $this->miner->createRuleModels($context->user, $result['candidates']);

            PipelineAuditEntry::create([
                'pipeline_run_id' => $context->pipelineRun->id,
                'stage' => self::STAGE_KEY,
                'action' => 'rules_created',
                'metadata' => [
                    'rules_created' => count($rules),
                    'rule_ids' => array_map(fn (UserRule $rule): int => $rule->id, $rules),
                    'ambiguous' => count($result['ambiguous']),
                    'conflicts' => count($result['conflicts']),
                    'contradictions' => count($result['contradictions']),
                ],
            ]);
        });

        return new StageResult(success: true, stage: self::STAGE_KEY);
    }

    private function hasMined(PipelineContext $context): bool
    {
        return $this->userAudit($context, 'rules_created')->exists();
    }

    private function hasFailedAttempt(PipelineContext $context): bool
    {
        return $this->userAudit($context, 'failed')->exists();
    }

    /** @return Builder<PipelineAuditEntry> */
    private function userAudit(PipelineContext $context, string $action): Builder
    {
        return PipelineAuditEntry::query()
            ->where('stage', self::STAGE_KEY)
            ->where('action', $action)
            ->whereHas('pipelineRun', fn ($query) => $query->where('user_id', $context->user->id));
    }

    /**
     * @return list<array{value: string, category_id: int, source: string, extra_triggers: list<array{field: string, operator: string, value: string}>}>
     */
    private function incomeSeeds(PipelineContext $context): array
    {
        $salaryCategoryId = Category::allWithLinkedParents()
            ->first(fn (Category $category): bool => mb_strtolower($category->fullPath()) === mb_strtolower(self::SALARY_CATEGORY_PATH))
            ?->id;

        if ($salaryCategoryId === null) {
            return [];
        }

        $seeds = [];

        foreach ($context->user->accounts()->active()->tracked()->get() as $account) {
            $pattern = $this->incomeDetector->detectForAccount($account);

            if ($pattern === null || $pattern->confidence < IncomePatternDetector::AUTO_APPLY_CONFIDENCE) {
                continue;
            }

            $value = $this->commonDescription(
                Transaction::query()->whereIn('id', $pattern->transactionIds)->pluck('description')->all(),
            );

            if ($value === null) {
                continue;
            }

            $seeds[mb_strtolower($value)] = [
                'value' => $value,
                'category_id' => (int) $salaryCategoryId,
                'source' => 'income',
                'extra_triggers' => [[
                    'field' => RuleTriggerField::Direction->value,
                    'operator' => RuleTriggerOperator::Is->value,
                    'value' => TransactionDirection::Credit->value,
                ]],
            ];
        }

        return array_values($seeds);
    }

    /**
     * The most distinctive run of words shared by every description, with
     * digit-only tokens (per-payment references) breaking runs and generic
     * bank words ignored at the edges. Null when nothing distinctive is shared.
     *
     * @param  list<string>  $descriptions
     */
    private function commonDescription(array $descriptions): ?string
    {
        if ($descriptions === []) {
            return null;
        }

        $best = null;
        $bestScore = self::MIN_SIGNIFICANT_LENGTH - 1;

        foreach ($this->tokenRuns($descriptions[0]) as $run) {
            $count = count($run);

            for ($start = 0; $start < $count; $start++) {
                for ($end = $start; $end < $count; $end++) {
                    $tokens = array_slice($run, $start, $end - $start + 1);

                    if ($this->isGeneric($tokens[0]) || $this->isGeneric($tokens[count($tokens) - 1])) {
                        continue;
                    }

                    $candidate = implode(' ', $tokens);
                    $score = array_sum(array_map(
                        fn (string $token): int => $this->isGeneric($token) ? 0 : mb_strlen($token),
                        $tokens,
                    ));

                    if ($score <= $bestScore || ! array_all($descriptions, fn (string $description): bool => mb_stripos($description, $candidate) !== false)) {
                        continue;
                    }

                    $best = $candidate;
                    $bestScore = $score;
                }
            }
        }

        return $best;
    }

    /** @return list<list<string>> */
    private function tokenRuns(string $description): array
    {
        $runs = [];
        $current = [];

        foreach (preg_split('/\s+/', mb_trim($description), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (preg_match('/^\d+$/', $token) === 1) {
                if ($current !== []) {
                    $runs[] = $current;
                }

                $current = [];

                continue;
            }

            $current[] = $token;
        }

        if ($current !== []) {
            $runs[] = $current;
        }

        return $runs;
    }

    private function isGeneric(string $token): bool
    {
        if (in_array(mb_strtoupper(preg_replace('/[^\p{L}\p{N}]+/u', '', $token) ?? $token), self::GENERIC_TOKENS, true)) {
            return true;
        }

        $parts = array_filter(preg_split('/[^\p{L}\p{N}]+/u', $token) ?: [], static fn (string $part): bool => $part !== '');

        return $parts !== [] && array_all($parts, fn (string $part): bool => in_array(mb_strtoupper($part), self::GENERIC_TOKENS, true));
    }

    /**
     * @param  array{value: string, category_id: int, source: string, extra_triggers?: list<array{field: string, operator: string, value: string}>}  $entry
     * @param  Collection<int, Transaction>  $transactions
     */
    private function matchesAny(array $entry, Collection $transactions): bool
    {
        $probe = new UserRule([
            'triggers' => $this->miner->triggersFor($entry),
            'strict_mode' => true,
        ]);

        return $transactions->contains(fn (Transaction $transaction): bool => $this->evaluator->matches($transaction, $probe));
    }
}
