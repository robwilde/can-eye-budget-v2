<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\TypeSafeServiceContract;
use App\DTOs\TypeSafeChoiceAnswer;
use App\Enums\CategorySource;
use App\Enums\TransactionDirection;
use App\Exceptions\TypeSafe\TypeSafeException;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Offline evaluation (#505): how accurate and how calibrated is TypeSafe Jev at
 * categorising a debit from its merchant name alone? Read-only; never writes.
 *
 * Data minimisation is enforced here, not left to the caller: only debits with a
 * merchant_name, no transfers, one question per merchant_key, digit runs masked, and
 * no description, amount, date, user identity or history ever leaves the app.
 */
final class JevEvalCommand extends Command
{
    /**
     * Jev input price, USD per million input tokens; output tokens are free
     * (docs.typesafe.ai/models, 2026-09). Only used for the estimate in the report.
     */
    private const float INPUT_USD_PER_MILLION_TOKENS = 0.042;

    /** Share of ground truth, newest first, held out for evaluation. */
    private const float HOLD_OUT_SHARE = 0.2;

    /** A top-2 gap below this counts as a close call in the margin report. */
    private const float CLOSE_MARGIN = 0.2;

    /** Lower bounds of the confidence buckets, highest first. */
    private const array CONFIDENCE_BUCKETS = [0.9, 0.7, 0.5, 0.0];

    private const string TOP_INSTRUCTIONS = 'Which budget category does a purchase from this merchant belong to?';

    private const string CHILD_INSTRUCTIONS = 'Which budget category, within the chosen group, does a purchase from this merchant belong to?';

    private const string HINT_NOTE = 'unverified_hint comes from the bank feed and may be wrong.';

    protected $signature = 'categories:jev-eval
        {--user= : Restrict ground truth to this user id; omit for every user}
        {--limit= : Evaluate at most this many held-out merchants}';

    protected $description = 'Measure TypeSafe Jev accuracy and calibration on merchant-only categorisation against manually categorised history (read-only)';

    private int $inputTokens = 0;

    private int $outputTokens = 0;

    private int $calls = 0;

    /** @var array<string, true> */
    private array $models = [];

    /** Replace every digit run: store numbers, card fragments and references are not the merchant. */
    public static function maskDigits(string $merchantName): string
    {
        return (string) preg_replace('/\d+/u', '#', $merchantName);
    }

    public function handle(): int
    {
        if (blank(config('services.typesafe.api_key'))) {
            $this->error('TYPESAFE_API_KEY is not configured.');

            return self::FAILURE;
        }

        $typeSafe = app(TypeSafeServiceContract::class);

        $categories = Category::visibleSortedByFullPath()->keyBy('id');
        $heldOut = $this->heldOut($categories);

        if ($heldOut->isEmpty()) {
            $this->warn('No manually categorised merchant debits to evaluate.');

            return self::SUCCESS;
        }

        $hubs = $categories->filter(fn (Category $category): bool => $category->parent_id === null);

        try {
            $results = [
                'no hint' => $this->evaluate($typeSafe, $heldOut, $categories, $hubs, withHint: false),
                'with hint' => $this->evaluate($typeSafe, $heldOut, $categories, $hubs, withHint: true),
            ];
        } catch (TypeSafeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->report($results, $categories);

        return self::SUCCESS;
    }

    /**
     * Newest manual categorisation per merchant_key, newest 20% of merchants kept.
     *
     * @param  Collection<int, Category>  $categories  visible categories by id
     * @return Collection<int, Transaction>
     */
    private function heldOut(Collection $categories): Collection
    {
        $userId = $this->option('user');

        $truth = Transaction::query()
            ->select(['id', 'merchant_key', 'merchant_name', 'category_id', 'enrich_data', 'post_date'])
            ->where('direction', TransactionDirection::Debit)
            ->whereNull('transfer_pair_id')
            ->where('category_source', CategorySource::Manual)
            ->whereNotNull('category_id')
            ->whereNotNull('merchant_key')
            ->whereNotNull('merchant_name')
            ->where('merchant_name', '!=', '')
            ->when(filled($userId), fn ($query) => $query->where('user_id', (int) $userId))
            ->orderByDesc('post_date')
            ->orderByDesc('id')
            ->get()
            ->unique('merchant_key')
            ->filter(fn (Transaction $row): bool => $this->hubOf($categories, (int) $row->category_id) !== null)
            ->values();

        $count = (int) ceil($truth->count() * self::HOLD_OUT_SHARE);
        $limit = $this->option('limit');

        if (filled($limit)) {
            $count = min($count, max(0, (int) $limit));
        }

        return $truth->take($count);
    }

    /**
     * @param  Collection<int, Transaction>  $rows
     * @param  Collection<int, Category>  $categories
     * @param  Collection<int, Category>  $hubs
     * @return list<array{truth: int, truth_hub: int, predicted: int, predicted_hub: int, top3: list<int>, confidence: float, margin: float}>
     */
    private function evaluate(TypeSafeServiceContract $typeSafe, Collection $rows, Collection $categories, Collection $hubs, bool $withHint): array
    {
        $topOptions = $this->criteriaFor($hubs);
        $results = [];

        foreach ($rows as $row) {
            $state = $this->state($row, $withHint);

            $top = $this->askJev($typeSafe, $state, self::TOP_INSTRUCTIONS, $topOptions);
            $hubId = $this->idOf($top->choice);

            $family = $categories->filter(fn (Category $category): bool => $this->hubOf($categories, $category->id)?->id === $hubId);

            if ($family->count() < 2) {
                $predicted = $hubId;
                $top3 = [$hubId];
                $confidence = $top->probabilities[$top->choice] ?? $top->confidence;
                $margin = $top->margin();
            } else {
                $child = $this->askJev($typeSafe, $state, self::CHILD_INSTRUCTIONS, $this->criteriaFor($family));
                $predicted = $this->idOf($child->choice);
                $top3 = array_map($this->idOf(...), array_slice($child->ranked(), 0, 3));
                // Joint probability of the predicted path, and the closer of the two calls.
                $confidence = ($top->probabilities[$top->choice] ?? 0.0) * ($child->probabilities[$child->choice] ?? 0.0);
                $margin = min($top->margin(), $child->margin());
            }

            $results[] = [
                'truth' => (int) $row->category_id,
                'truth_hub' => (int) $this->hubOf($categories, (int) $row->category_id)?->id,
                'predicted' => $predicted,
                'predicted_hub' => $hubId,
                'top3' => $top3,
                'confidence' => $confidence,
                'margin' => $margin,
            ];
        }

        return $results;
    }

    /**
     * The only data sent: the masked merchant name, plus the feed's own category and MCC
     * as an explicitly unverified hint in the hinted variant.
     *
     * @return array<string, mixed>
     */
    private function state(Transaction $row, bool $withHint): array
    {
        $state = ['merchant_name' => self::maskDigits((string) $row->merchant_name)];

        if (! $withHint) {
            return $state;
        }

        $redbark = $row->enrich_data['redbark'] ?? [];
        $hint = array_filter([
            'bank_category' => is_string($redbark['category'] ?? null) ? $redbark['category'] : null,
            'mcc' => is_scalar($redbark['merchantCategoryCode'] ?? null) ? (string) $redbark['merchantCategoryCode'] : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        if ($hint !== []) {
            $state['unverified_hint'] = $hint;
            $state['note'] = self::HINT_NOTE;
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, string>  $options
     */
    private function askJev(TypeSafeServiceContract $typeSafe, array $state, string $instructions, array $options): TypeSafeChoiceAnswer
    {
        $answer = $typeSafe->choose($state, $instructions, $options);

        $this->calls++;
        $this->inputTokens += $answer->inputTokens;
        $this->outputTokens += $answer->outputTokens;
        $this->models[$answer->model] = true;

        return $answer;
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return array<string, string> option id => Category::fullPath()
     */
    private function criteriaFor(Collection $categories): array
    {
        return $categories->mapWithKeys(fn (Category $category): array => ['c'.$category->id => $category->fullPath()])->all();
    }

    private function idOf(string $optionId): int
    {
        return (int) mb_substr($optionId, 1);
    }

    /**
     * The visible top-level ancestor of a visible category, or null when it or any ancestor is hidden.
     *
     * @param  Collection<int, Category>  $categories
     */
    private function hubOf(Collection $categories, int $categoryId): ?Category
    {
        $category = $categories->get($categoryId);

        while ($category !== null && $category->parent_id !== null) {
            $category = $categories->get($category->parent_id);
        }

        return $category;
    }

    /**
     * @param  array<string, list<array{truth: int, truth_hub: int, predicted: int, predicted_hub: int, top3: list<int>, confidence: float, margin: float}>>  $results
     * @param  Collection<int, Category>  $categories
     */
    private function report(array $results, Collection $categories): void
    {
        $variants = array_keys($results);
        $first = $results[$variants[0]];

        $this->info('Model: '.implode(', ', array_keys($this->models)));
        $this->info('Held-out merchants: '.count($first));

        $this->line('');
        $this->table(
            ['Metric', ...$variants],
            [
                ['Top-level accuracy', ...array_map(fn (array $r): string => $this->rate($r, fn (array $x): bool => $x['predicted_hub'] === $x['truth_hub']), $results)],
                ['Top-1 accuracy', ...array_map(fn (array $r): string => $this->rate($r, fn (array $x): bool => $x['predicted'] === $x['truth']), $results)],
                ['Top-3 accuracy', ...array_map(fn (array $r): string => $this->rate($r, fn (array $x): bool => in_array($x['truth'], $x['top3'], true)), $results)],
            ],
        );

        $this->line('');
        $this->info('Top-1 accuracy per top-level category:');
        $hubIds = array_values(array_unique(array_column($first, 'truth_hub')));
        $this->table(
            ['Top-level category', 'n', ...$variants],
            array_map(fn (int $hubId): array => [
                $categories->get($hubId)->name ?? '?',
                (string) count(array_filter($first, fn (array $x): bool => $x['truth_hub'] === $hubId)),
                ...array_map(fn (array $r): string => $this->rate(
                    array_values(array_filter($r, fn (array $x): bool => $x['truth_hub'] === $hubId)),
                    fn (array $x): bool => $x['predicted'] === $x['truth'],
                ), $results),
            ], $hubIds),
        );

        foreach ($results as $variant => $rows) {
            $this->line('');
            $this->info("Calibration ({$variant}): confidence = joint probability of the predicted path");
            $this->table(['Confidence', 'n', 'Precision'], $this->calibration($rows));

            $correct = array_values(array_filter($rows, fn (array $x): bool => $x['predicted'] === $x['truth']));
            $wrong = array_values(array_filter($rows, fn (array $x): bool => $x['predicted'] !== $x['truth']));
            $close = array_values(array_filter($rows, fn (array $x): bool => $x['margin'] < self::CLOSE_MARGIN));
            $clear = array_values(array_filter($rows, fn (array $x): bool => $x['margin'] >= self::CLOSE_MARGIN));
            $isCorrect = fn (array $x): bool => $x['predicted'] === $x['truth'];

            $this->info("Top-2 margin ({$variant}):");
            $this->table(['Group', 'n', 'Value'], [
                ['Mean margin, correct', (string) count($correct), $this->mean(array_column($correct, 'margin'))],
                ['Mean margin, wrong', (string) count($wrong), $this->mean(array_column($wrong, 'margin'))],
                ['Precision, margin < '.self::CLOSE_MARGIN, (string) count($close), $this->rate($close, $isCorrect)],
                ['Precision, margin >= '.self::CLOSE_MARGIN, (string) count($clear), $this->rate($clear, $isCorrect)],
            ]);
        }

        $this->line('');
        $this->info(sprintf(
            'Usage: %d calls, %d input tokens, %d output tokens, estimated cost $%.6f USD (at $'.self::INPUT_USD_PER_MILLION_TOKENS.'/M input tokens)',
            $this->calls,
            $this->inputTokens,
            $this->outputTokens,
            $this->inputTokens / 1_000_000 * self::INPUT_USD_PER_MILLION_TOKENS,
        ));
    }

    /**
     * @param  list<array{predicted: int, truth: int, confidence: float}>  $rows
     * @return list<list<string>>
     */
    private function calibration(array $rows): array
    {
        $table = [];
        $upper = 1.0;

        foreach (self::CONFIDENCE_BUCKETS as $lower) {
            $bucket = array_values(array_filter(
                $rows,
                fn (array $x): bool => $x['confidence'] >= $lower && ($x['confidence'] < $upper || $upper === 1.0),
            ));
            $table[] = [
                sprintf('%.1f–%.1f', $lower, $upper),
                (string) count($bucket),
                $this->rate($bucket, fn (array $x): bool => $x['predicted'] === $x['truth']),
            ];
            $upper = $lower;
        }

        return $table;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): bool  $hit
     */
    private function rate(array $rows, callable $hit): string
    {
        if ($rows === []) {
            return 'n/a';
        }

        $hits = count(array_filter($rows, $hit));

        return sprintf('%.1f%% (%d/%d)', $hits / count($rows) * 100, $hits, count($rows));
    }

    /** @param  list<float>  $values */
    private function mean(array $values): string
    {
        return $values === [] ? 'n/a' : sprintf('%.3f', array_sum($values) / count($values));
    }
}
