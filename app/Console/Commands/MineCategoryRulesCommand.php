<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PipelineTrigger;
use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Services\CategoryRuleMiner;
use App\Services\RuleEvaluator;
use App\Services\TransactionAnalysisPipeline;
use App\Support\Transactions\MerchantMatchValue;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use League\Csv\Exception as CsvException;
use League\Csv\Reader;

final class MineCategoryRulesCommand extends Command
{
    protected $signature = 'categories:mine-rules
        {--user=1 : The user id to mine rules for}
        {--account=* : Restrict mining to these account ids}
        {--dry-run : Report the candidates without writing or applying anything}
        {--coverage-dir= : Score rule coverage against every CSV in this directory}';

    protected $description = 'Mine category rules from a user\'s categorised history (plus curated seeds) so CSV imports arrive categorised';

    public function handle(
        CategoryRuleMiner $miner,
        RuleEvaluator $evaluator,
        TransactionAnalysisPipeline $pipeline,
    ): int {
        $user = User::query()->find((int) $this->option('user'));

        if ($user === null) {
            $this->error('User '.$this->option('user').' not found.');

            return self::FAILURE;
        }

        /** @var list<int> $accountIds */
        $accountIds = array_map('intval', (array) $this->option('account'));
        $dryRun = (bool) $this->option('dry-run');

        $coverageDirOption = $this->option('coverage-dir');
        $coverageDir = is_string($coverageDirOption) && $coverageDirOption !== '' ? $coverageDirOption : null;

        // Coverage scores the rules as they were *before* this run wrote anything,
        // plus the synthetic ones below, so it has to be captured up front.
        $activeRules = $coverageDir === null ? null : $miner->activeRules($user);

        $result = $miner->mine($user, $accountIds);
        $final = $result['candidates'];

        $categoryNames = Category::query()->pluck('name', 'id');

        $this->reportCandidates($final, $categoryNames);
        $this->reportAmbiguous($result['ambiguous'], $categoryNames);
        $this->reportConflicts($result['conflicts'], $categoryNames);

        if ($result['skippedSeeds'] !== []) {
            $this->warn(
                'Skipped '.count($result['skippedSeeds'])
                .' seed(s) whose category does not exist here: '
                .implode(', ', $result['skippedSeeds']).'.'
            );
        }

        // Diagnostic, not a gate. Seeds resolve by category path and unresolvable
        // ones are skipped above, and transactions.category_id is nullOnDelete, so
        // a mined candidate cannot reference a category that no longer exists.
        if ($result['unknownCategoryIds'] !== []) {
            $this->warn('Unknown category id(s) referenced by rules: '.implode(', ', $result['unknownCategoryIds']).'.');
        }

        if ($dryRun) {
            $this->warn('Dry run: no rules were written and the pipeline was not run.');
        } else {
            $created = $miner->createRules($user, $final);
            $this->info("Created {$created} rule(s) in the '".CategoryRuleMiner::GROUP_NAME."' group.");

            $run = $pipeline->run($user, PipelineTrigger::Manual);
            $this->info("Pipeline run #{$run->id} finished with status {$run->status->value}.");
        }

        if ($coverageDir !== null && $activeRules !== null) {
            $this->reportCoverage($coverageDir, $activeRules, $final, $evaluator);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{value: string, category_id: int, source: string}>  $final
     * @param  Collection<int, string>  $categoryNames
     */
    private function reportCandidates(array $final, Collection $categoryNames): void
    {
        $this->line('');
        $this->info('Rules to create: '.count($final));

        if ($final === []) {
            return;
        }

        $this->table(
            ['Value', 'Category', 'Source'],
            array_map(fn (array $entry): array => [
                $entry['value'],
                $entry['category_id'].' '.($categoryNames[$entry['category_id']] ?? '?'),
                $entry['source'],
            ], $final),
        );
    }

    /**
     * @param  list<array{value: string, categories: list<int>}>  $ambiguous
     * @param  Collection<int, string>  $categoryNames
     */
    private function reportAmbiguous(array $ambiguous, Collection $categoryNames): void
    {
        $this->line('');
        $this->info('Ambiguous merchants (skipped): '.count($ambiguous));

        if ($ambiguous === []) {
            return;
        }

        $this->table(
            ['Value', 'Categories'],
            array_map(fn (array $entry): array => [
                $entry['value'],
                implode(', ', array_map(fn (int $id): string => $id.' '.($categoryNames[$id] ?? '?'), $entry['categories'])),
            ], $ambiguous),
        );
    }

    /**
     * @param  list<array{value: string, candidate: int, rule_id: int, rule_category: int}>  $conflicts
     * @param  Collection<int, string>  $categoryNames
     */
    private function reportConflicts(array $conflicts, Collection $categoryNames): void
    {
        $this->line('');
        $this->info('Conflicts (skipped): '.count($conflicts));

        if ($conflicts === []) {
            return;
        }

        $this->table(
            ['Value', 'Mined category', 'Existing rule', 'Rule category'],
            array_map(fn (array $entry): array => [
                $entry['value'],
                $entry['candidate'].' '.($categoryNames[$entry['candidate']] ?? '?'),
                '#'.$entry['rule_id'],
                $entry['rule_category'].' '.($categoryNames[$entry['rule_category']] ?? '?'),
            ], $conflicts),
        );
    }

    /**
     * @param  Collection<int, UserRule>  $activeRules
     * @param  list<array{value: string, category_id: int, source: string}>  $final
     */
    private function reportCoverage(string $coverageDir, Collection $activeRules, array $final, RuleEvaluator $evaluator): void
    {
        $rules = [...$activeRules->all(), ...$this->syntheticRules($final)];

        $files = glob(mb_rtrim($coverageDir, '/').'/*.csv') ?: [];
        sort($files);

        $rows = [];
        $matchedTotal = 0;
        $rowTotal = 0;
        $unmatched = [];

        foreach ($files as $file) {
            [$matched, $total, $fileUnmatched] = $this->scoreFile($file, $rules, $evaluator);

            foreach ($fileUnmatched as $key => $count) {
                $unmatched[$key] = ($unmatched[$key] ?? 0) + $count;
            }

            $matchedTotal += $matched;
            $rowTotal += $total;
            $rows[] = [basename($file), $matched.'/'.$total, $this->percent($matched, $total)];
        }

        $rows[] = ['TOTAL', $matchedTotal.'/'.$rowTotal, $this->percent($matchedTotal, $rowTotal)];

        $this->line('');
        $this->info('Coverage (non-fee rows) against '.$coverageDir.':');
        $this->table(['File', 'Matched', 'Coverage'], $rows);

        if ($unmatched === []) {
            return;
        }

        arsort($unmatched);
        $this->line('');
        $this->info('Unmatched merchants:');
        $this->table(
            ['Merchant', 'Occurrences'],
            array_map(fn (string $key, int $count): array => [$key, (string) $count], array_keys($unmatched), array_values($unmatched)),
        );
    }

    /**
     * @param  list<UserRule>  $rules
     * @return array{0: int, 1: int, 2: array<string, int>}
     */
    private function scoreFile(string $file, array $rules, RuleEvaluator $evaluator): array
    {
        $matched = 0;
        $total = 0;
        $unmatched = [];

        try {
            $reader = Reader::from($file);
            $reader->setHeaderOffset(0);
            $reader->skipInputBOM();

            foreach ($reader->getRecords() as $record) {
                $description = mb_trim($record['Transaction Description'] ?? '');

                if ($description === ''
                    || str_starts_with($description, 'Purchases - Month End Balance')
                    || str_starts_with($description, 'Int Tran Fee')) {
                    continue;
                }

                $total++;

                $transaction = new Transaction(['description' => $description, 'amount' => 0]);

                if ($this->descriptionIsCategorised($transaction, $rules, $evaluator)) {
                    $matched++;

                    continue;
                }

                $key = MerchantMatchValue::for($description) ?? $description;
                $unmatched[$key] = ($unmatched[$key] ?? 0) + 1;
            }
        } catch (CsvException) {
            $total++;
            $unmatched['(unparseable row)'] = 1;
        }

        return [$matched, $total, $unmatched];
    }

    /**
     * @param  list<UserRule>  $rules
     */
    private function descriptionIsCategorised(Transaction $transaction, array $rules, RuleEvaluator $evaluator): bool
    {
        foreach ($rules as $rule) {
            if (! $evaluator->matches($transaction, $rule)) {
                continue;
            }

            foreach ($rule->actions as $action) {
                if (($action['type'] ?? null) === RuleActionType::SetCategory->value) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array{value: string, category_id: int, source: string}>  $final
     * @return list<UserRule>
     */
    private function syntheticRules(array $final): array
    {
        return array_map(fn (array $entry): UserRule => new UserRule([
            'triggers' => [[
                'field' => RuleTriggerField::Description->value,
                'operator' => RuleTriggerOperator::Contains->value,
                'value' => $entry['value'],
            ]],
            'actions' => [[
                'type' => RuleActionType::SetCategory->value,
                'value' => (string) $entry['category_id'],
            ]],
            'strict_mode' => true,
        ]), $final);
    }

    private function percent(int $matched, int $total): string
    {
        if ($total === 0) {
            return 'n/a';
        }

        return round($matched / $total * 100, 1).'%';
    }
}
