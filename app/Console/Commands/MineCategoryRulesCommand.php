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
use App\Models\UserRuleGroup;
use App\Services\CategoryRuleGenerator;
use App\Services\RuleEvaluator;
use App\Services\TransactionAnalysisPipeline;
use App\Support\Transactions\MerchantMatchValue;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use League\Csv\Exception as CsvException;
use League\Csv\Reader;

final class MineCategoryRulesCommand extends Command
{
    private const string GROUP_NAME = 'Auto-categorisation';

    /** @var array<string, int> */
    private const array SEED_RULES = [
        'PRIMEVIDEO' => 35,
        'PHIND.COM' => 5,
        'OPENAI *CHATGPT' => 5,
        'PINECONE' => 5,
        'RECALL' => 5,
        'WARP PRO' => 5,
        'SUBSTACK.COM' => 6,
        'CODINGCHALLENGES' => 6,
        'PADDLE.NET* DAILY.DEV' => 6,
        'PHPARCH.COM' => 6,
        'LEARN PROMPTING' => 9,
        'TBL* NET NINJA' => 9,
        'TBL* STREET-SMART' => 9,
        'GUMROAD* MARTIN JOO' => 9,
        'Datacamp' => 8,
        'OBICO' => 13,
        '3DEXPERIENCE' => 13,
        'PAYPAL *CLOUDNS' => 2,
        'RESCUETIME' => 86,
        'DRAWSQL' => 86,
        'APIFY' => 86,
        'SP LUMEN.ME' => 18,
        'PAYPAL *GLUCOSEGODD' => 18,
        'PAYPAL *MADMUSL' => 18,
        'HEADSPACE' => 18,
        'Next Practice' => 18,
        'PET CIRCLE' => 23,
        'SP CELERY PETS' => 23,
        'SP MEOWVO' => 23,
        'KITTECUBE.COM' => 23,
        'SP AUSSIEWOOF' => 23,
        'SP RUFUS AND COCO' => 23,
        'SP MICHU' => 23,
        'GP VET' => 23,
        'CAT SNACKS' => 23,
        'HUBBL - BINGE' => 35,
        'Spotify' => 35,
        'AMZNPRIMEAU' => 35,
        'STEAM PURCHASE' => 39,
        'PAYPAL *TWITCHINTER' => 38,
        'PAYPAL *GISMART' => 12,
        'PAYPAL *IMPULSE' => 12,
        'Google XAPPIFY' => 12,
        'Google Pujie' => 12,
        'Google Hiya' => 12,
        'Google SYGIC' => 12,
        'Google OBD2 Car Scann' => 12,
        'FLEXJOBS' => 87,
        'REMOTEJOBS.IO' => 87,
        'jobleads.com' => 87,
        'SP THEPERFECTRESUME' => 87,
        'Upwork' => 87,
        'EQUIFAX' => 20,
        'HEART RESEARCH' => 33,
        'Credit Card Interest' => 21,
        'Purchase Interest' => 21,
        'Card Replacement Fee' => 21,
        'Insufficient funds' => 21,
        'LIBERTY HIGHGATE HILL' => 78,
        'Reddy Express' => 78,
        'LINKT' => 81,
        'READING NEWMARKET' => 42,
        'CELLOPARK' => 82,
        'SQ *THE KEBAB SHOP' => 47,
        'SUBWAY' => 47,
        'EATCLUB' => 46,
        'SQ *MOTORCYCLE FREIGH' => 77,
        'ENGINEERING LEADERSHI' => 6,
        'PAYPAL *LUCENTGLOBE' => 45,
        'ONLYFANS' => 37,
        'Optmus to CC' => 62,
        'AUSSIE BROADBAND LIMI' => 52,
        'LinkedIn' => 87,
        'Google Workspace' => 2,
        'GSUITE' => 2,
        'Google YouTube' => 35,
        'GOOGLE*YOUTUBE' => 35,
    ];

    protected $signature = 'categories:mine-rules
        {--user=1 : The user id to mine rules for}
        {--account=* : Restrict mining to these account ids}
        {--dry-run : Report the candidates without writing or applying anything}
        {--coverage-dir= : Score rule coverage against every CSV in this directory}';

    protected $description = 'Mine category rules from a user\'s categorised history (plus curated seeds) so CSV imports arrive categorised';

    public function handle(
        RuleEvaluator $evaluator,
        CategoryRuleGenerator $generator,
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

        $activeRules = $this->activeRules($user);
        $categoryNames = Category::query()->pluck('name', 'id');

        $candidates = [];
        $ambiguous = [];
        $conflicts = [];

        foreach ($this->minedGroups($user, $accountIds, $generator) as $group) {
            $categoryIds = array_keys($group['category_ids']);

            if (count($categoryIds) > 1) {
                sort($categoryIds);
                $ambiguous[] = ['value' => $group['value'], 'categories' => $categoryIds];

                continue;
            }

            $categoryId = $categoryIds[0];
            $matchingCategories = $this->matchingRuleCategories($group['sample'], $activeRules, $evaluator);

            if (isset($matchingCategories[$categoryId])) {
                continue;
            }

            if ($matchingCategories !== []) {
                $ruleCategory = array_key_first($matchingCategories);
                $conflicts[] = [
                    'value' => $group['value'],
                    'candidate' => $categoryId,
                    'rule_id' => $matchingCategories[$ruleCategory]->id,
                    'rule_category' => $ruleCategory,
                ];

                continue;
            }

            $candidates[] = ['value' => $group['value'], 'category_id' => $categoryId, 'source' => 'mined'];
        }

        $seeds = [];
        foreach (self::SEED_RULES as $value => $categoryId) {
            $seeds[] = ['value' => $value, 'category_id' => $categoryId, 'source' => 'seed'];
        }

        $final = $this->dedupeAndSubsume([...$seeds, ...$candidates], $this->existingTriggerValues($activeRules));

        $this->reportCandidates($final, $categoryNames);
        $this->reportAmbiguous($ambiguous, $categoryNames);
        $this->reportConflicts($conflicts, $categoryNames);

        $unknownCategories = $this->unknownCategoryIds($final, $categoryNames);
        if ($unknownCategories !== []) {
            $this->error('Unknown category id(s) referenced by rules: '.implode(', ', $unknownCategories).'.');
            $this->error('Category ids are environment-specific; verify SEED_RULES against this database before applying.');

            if (! $dryRun) {
                return self::FAILURE;
            }
        }

        if ($dryRun) {
            $this->warn('Dry run: no rules were written and the pipeline was not run.');
        } else {
            $created = $this->createRules($user, $final);
            $this->info("Created {$created} rule(s) in the '".self::GROUP_NAME."' group.");

            $run = $pipeline->run($user, PipelineTrigger::Manual);
            $this->info("Pipeline run #{$run->id} finished with status {$run->status->value}.");
        }

        $coverageDir = $this->option('coverage-dir');
        if (is_string($coverageDir) && $coverageDir !== '') {
            $this->reportCoverage($coverageDir, $activeRules, $final, $evaluator);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{value: string, category_id: int, source: string}>  $final
     * @param  Collection<int, string>  $categoryNames
     * @return list<int>
     */
    private function unknownCategoryIds(array $final, Collection $categoryNames): array
    {
        $unknown = [];

        foreach ($final as $entry) {
            if (! $categoryNames->has($entry['category_id'])) {
                $unknown[$entry['category_id']] = true;
            }
        }

        $ids = array_keys($unknown);
        sort($ids);

        return $ids;
    }

    /** @return Collection<int, UserRule> */
    private function activeRules(User $user): Collection
    {
        return UserRule::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereHas('group', fn ($query) => $query->where('is_active', true))
            ->ordered()
            ->get();
    }

    /**
     * @param  list<int>  $accountIds
     * @return array<string, array{value: string, category_ids: array<int, bool>, sample: Transaction}>
     */
    private function minedGroups(User $user, array $accountIds, CategoryRuleGenerator $generator): array
    {
        $groups = [];

        Transaction::query()
            ->where('user_id', $user->id)
            ->whereNotNull('category_id')
            ->whereNull('folded_into_transaction_id')
            ->current()
            ->when($accountIds !== [], fn ($query) => $query->whereIn('account_id', $accountIds))
            ->lazyById()
            ->each(function (Transaction $transaction) use (&$groups, $generator): void {
                $value = MerchantMatchValue::for($transaction->description) ?? $generator->suggestMatchValue($transaction);
                $key = mb_strtolower($value);

                if (! isset($groups[$key])) {
                    $groups[$key] = ['value' => $value, 'category_ids' => [], 'sample' => $transaction];
                }

                $groups[$key]['category_ids'][(int) $transaction->category_id] = true;
            });

        return $groups;
    }

    /**
     * @param  Collection<int, UserRule>  $activeRules
     * @return array<int, UserRule>
     */
    private function matchingRuleCategories(Transaction $sample, Collection $activeRules, RuleEvaluator $evaluator): array
    {
        $matches = [];

        foreach ($activeRules as $rule) {
            if (! $evaluator->matches($sample, $rule)) {
                continue;
            }

            $categoryId = $this->ruleCategory($rule);

            if ($categoryId !== null && ! isset($matches[$categoryId])) {
                $matches[$categoryId] = $rule;
            }
        }

        return $matches;
    }

    private function ruleCategory(UserRule $rule): ?int
    {
        foreach ($rule->actions as $action) {
            if (($action['type'] ?? null) === RuleActionType::SetCategory->value) {
                return (int) $action['value'];
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, UserRule>  $activeRules
     * @return array<string, true>
     */
    private function existingTriggerValues(Collection $activeRules): array
    {
        $values = [];

        foreach ($activeRules as $rule) {
            foreach ($rule->triggers as $trigger) {
                $value = $trigger['value'] ?? '';

                if ($value !== '') {
                    $values[mb_strtolower($value)] = true;
                }
            }
        }

        return $values;
    }

    /**
     * @param  list<array{value: string, category_id: int, source: string}>  $entries
     * @param  array<string, true>  $existingValues
     * @return list<array{value: string, category_id: int, source: string}>
     */
    private function dedupeAndSubsume(array $entries, array $existingValues): array
    {
        $byKey = [];

        foreach ($entries as $entry) {
            $key = mb_strtolower($entry['value']);

            if (isset($existingValues[$key]) || isset($byKey[$key])) {
                continue;
            }

            $byKey[$key] = $entry;
        }

        $merged = array_values($byKey);

        $final = [];

        foreach ($merged as $entry) {
            $subsumed = false;

            foreach ($merged as $other) {
                if ($other['category_id'] !== $entry['category_id']) {
                    continue;
                }

                if (mb_strtolower($other['value']) === mb_strtolower($entry['value'])) {
                    continue;
                }

                if (mb_stripos($entry['value'], $other['value']) !== false) {
                    $subsumed = true;
                    break;
                }
            }

            if (! $subsumed) {
                $final[] = $entry;
            }
        }

        return $final;
    }

    /**
     * @param  list<array{value: string, category_id: int, source: string}>  $final
     */
    private function createRules(User $user, array $final): int
    {
        if ($final === []) {
            return 0;
        }

        $group = $this->resolveGroup($user->id);
        $order = (int) UserRule::query()->where('user_rule_group_id', $group->id)->max('order');

        foreach ($final as $entry) {
            UserRule::query()->create([
                'user_id' => $user->id,
                'user_rule_group_id' => $group->id,
                'name' => mb_substr('Categorise '.$entry['value'], 0, 255),
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
                'is_auto_apply' => true,
                'is_active' => true,
                'order' => ++$order,
            ]);
        }

        return count($final);
    }

    private function resolveGroup(int $userId): UserRuleGroup
    {
        $existing = UserRuleGroup::query()
            ->where('user_id', $userId)
            ->where('name', self::GROUP_NAME)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return UserRuleGroup::query()->create([
            'user_id' => $userId,
            'name' => self::GROUP_NAME,
            'order' => (int) UserRuleGroup::query()->where('user_id', $userId)->max('order') + 1,
            'is_active' => true,
            'stop_processing' => false,
        ]);
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
            if ($evaluator->matches($transaction, $rule) && $this->ruleCategory($rule) !== null) {
                return true;
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
