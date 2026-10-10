<?php

declare(strict_types=1);

namespace App\Services\Payees;

use App\Console\Commands\JevEvalCommand;
use App\Contracts\TypeSafeServiceContract;
use App\DTOs\TypeSafeChoiceAnswer;
use App\Enums\CategorySource;
use App\Enums\MerchantBrandStatus;
use App\Enums\PayeeStatus;
use App\Enums\RuleActionType;
use App\Enums\TransactionDirection;
use App\Exceptions\TypeSafe\TypeSafeException;
use App\Models\Category;
use App\Models\MerchantBrand;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Services\RuleEvaluator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Asks TypeSafe Jev which budget category a payee belongs to, and records the answer.
 *
 * Data minimisation is enforced here: the only data that leaves the app is the
 * merchant name with every digit run masked, plus, when the user has taught the app
 * enough about similar payees, the category they usually choose for them. No
 * description, amount, date, user identity or other merchant name is ever sent.
 *
 * A confident answer is applied to the payee's uncategorised debits as a Suggested
 * category, which rules may overwrite and a person always can. Anything less leaves
 * the rows uncategorised for the review queue. A suggestion counts as confident only
 * when both the confidence and the gap between the top two options clear the line, so
 * a close call between categories (a conflict) is never applied. Where the question
 * takes two steps (root, then a category inside it) the weaker step decides.
 *
 * A merchant key that already has a Payee row, or that an active rule categorises,
 * is never sent to Jev again.
 */
final readonly class PayeeSuggester
{
    public const float AUTO_APPLY_CONFIDENCE = 0.80;

    public const float AUTO_APPLY_TOP_TO_SECOND = 3.0;

    private const float MAX_TOP_TO_SECOND = 9999.0;

    private const int MERCHANTS_PER_CHUNK = 50;

    private const int MIN_HINT_NEIGHBOURS = 2;

    private const string HINT_KEY = 'user_usually_categorises_similar_payees_as';

    private const string TOP_INSTRUCTIONS = 'Which budget category does a purchase from this merchant belong to?';

    private const string CHILD_INSTRUCTIONS = 'Which budget category, within the chosen group, does a purchase from this merchant belong to?';

    public function __construct(
        private TypeSafeServiceContract $typeSafe,
        private RuleEvaluator $evaluator,
    ) {}

    /**
     * Whether the user has an uncategorised merchant debit nobody has asked Jev about.
     */
    public function hasCandidates(User $user): bool
    {
        return $this->candidates($user)->exists();
    }

    /**
     * Suggest a category for the transaction's payee. Null when the transaction is not
     * the user's, is not an uncategorised merchant debit, or its payee is already known.
     *
     * @throws TypeSafeException
     */
    public function suggest(User $user, Transaction $transaction): ?Payee
    {
        $row = $this->candidates($user)->whereKey($transaction->getKey())->first();

        if ($row === null) {
            return null;
        }

        return $this->suggestFor($user, $row, $this->taxonomy(), $this->categoryRules($user));
    }

    /**
     * Suggest for every merchant the user has not been asked about.
     *
     * Payees written before a failure are kept.
     *
     * @return int how many payees were created
     *
     * @throws TypeSafeException
     */
    public function suggestForUser(User $user): int
    {
        $keys = $this->candidates($user)
            ->select('merchant_key')
            ->distinct()
            ->orderBy('merchant_key')
            ->pluck('merchant_key');

        if ($keys->isEmpty()) {
            return 0;
        }

        $taxonomy = $this->taxonomy();
        $rules = $this->categoryRules($user);
        $created = 0;

        foreach ($keys->chunk(self::MERCHANTS_PER_CHUNK) as $chunk) {
            $representatives = $this->candidates($user)
                ->whereIn('merchant_key', $chunk->all())
                ->orderByDesc('post_date')
                ->orderByDesc('id')
                ->get()
                ->unique('merchant_key');

            foreach ($representatives as $representative) {
                if ($this->suggestFor($user, $representative, $taxonomy, $rules) !== null) {
                    $created++;
                }
            }
        }

        return $created;
    }

    /**
     * Current, uncategorised, non-transfer, non-split merchant debits of the user.
     *
     * @return Builder<Transaction>
     */
    private function uncategorisedRows(User $user): Builder
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('direction', TransactionDirection::Debit)
            ->whereNull('category_id')
            ->whereNotNull('merchant_key')
            ->where('merchant_key', '!=', Transaction::UNKNOWN_MERCHANT_KEY)
            ->whereNotNull('merchant_name')
            ->where('merchant_name', '!=', '')
            ->excludingTransfers()
            ->current()
            ->whereDoesntHave('splits');
    }

    /**
     * @return Builder<Transaction>
     */
    private function candidates(User $user): Builder
    {
        return $this->uncategorisedRows($user)->whereNotIn(
            'merchant_key',
            Payee::query()->where('user_id', $user->id)->select('merchant_key'),
        );
    }

    /**
     * @param  array{categories: Collection<int, Category>, roots: Collection<int, Category>, families: array<int, Collection<int, Category>>}  $taxonomy
     * @param  Collection<int, UserRule>  $rules
     *
     * @throws TypeSafeException
     */
    private function suggestFor(User $user, Transaction $row, array $taxonomy, Collection $rules): ?Payee
    {
        if ($taxonomy['roots']->isEmpty() || $rules->contains(fn (UserRule $rule): bool => $this->evaluator->matches($row, $rule))) {
            return null;
        }

        $merchantKey = (string) $row->merchant_key;
        $merchantName = (string) $row->merchant_name;

        $state = ['merchant_name' => JevEvalCommand::maskDigits($merchantName)];
        $hint = $this->hintFor($user, $merchantKey, $taxonomy['categories']);

        if ($hint !== null) {
            $state[self::HINT_KEY] = $hint;
        }

        $answer = $this->ask($state, $taxonomy);

        $confident = $answer['categoryId'] !== null
            && $answer['confidence'] >= self::AUTO_APPLY_CONFIDENCE
            && $answer['topToSecond'] >= self::AUTO_APPLY_TOP_TO_SECOND;

        try {
            return DB::transaction(function () use ($user, $merchantKey, $merchantName, $answer, $confident): Payee {
                $payee = Payee::query()->create([
                    'user_id' => $user->id,
                    'merchant_key' => $merchantKey,
                    'merchant_name' => mb_substr($merchantName, 0, 255),
                    'status' => PayeeStatus::Pending,
                    'suggested_category_id' => $answer['categoryId'],
                    'confidence' => $answer['confidence'],
                    'top_to_second' => $answer['topToSecond'],
                    'probabilities' => $answer['probabilities'],
                    'auto_applied' => false,
                    'suggested_at' => now(),
                ]);

                if ($confident && $this->applyToUncategorised($user, $merchantKey, (int) $answer['categoryId']) > 0) {
                    $payee->update(['auto_applied' => true]);
                }

                return $payee;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array{categories: Collection<int, Category>, roots: Collection<int, Category>, families: array<int, Collection<int, Category>>}  $taxonomy
     * @return array{categoryId: int|null, confidence: float, topToSecond: float, probabilities: array<int, float>}
     *
     * @throws TypeSafeException
     */
    private function ask(array $state, array $taxonomy): array
    {
        $top = $this->typeSafe->choose($state, self::TOP_INSTRUCTIONS, $this->options($taxonomy['roots']));
        $rootId = $this->idOf($top->choice);
        $family = $taxonomy['families'][$rootId] ?? null;

        if ($family === null) {
            return ['categoryId' => null, 'confidence' => 0.0, 'topToSecond' => 0.0, 'probabilities' => []];
        }

        if ($family->count() - 1 < 2) {
            return [
                'categoryId' => $rootId,
                'confidence' => $top->probabilities[$top->choice] ?? $top->confidence,
                'topToSecond' => $this->topToSecond($top),
                'probabilities' => $this->probabilitiesById($top),
            ];
        }

        $child = $this->typeSafe->choose($state, self::CHILD_INSTRUCTIONS, $this->options($family));
        $categoryId = $this->idOf($child->choice);

        return [
            'categoryId' => $family->has($categoryId) ? $categoryId : null,
            'confidence' => ($top->probabilities[$top->choice] ?? 0.0) * ($child->probabilities[$child->choice] ?? 0.0),
            'topToSecond' => min($this->topToSecond($top), $this->topToSecond($child)),
            'probabilities' => $this->probabilitiesById($child),
        ];
    }

    /**
     * What the user usually picks for payees like this one, or null without enough evidence:
     * at least two of their confirmed payees in the same brand industry (and subindustry,
     * when known) and more than half of them agreeing on one category.
     *
     * @param  Collection<int, Category>  $categories  visible categories by id
     */
    private function hintFor(User $user, string $merchantKey, Collection $categories): ?string
    {
        $brand = MerchantBrand::query()
            ->where('user_id', $user->id)
            ->where('merchant_key', $merchantKey)
            ->where('status', MerchantBrandStatus::Resolved)
            ->first();

        if ($brand === null || blank($brand->industry)) {
            return null;
        }

        $neighbourKeys = MerchantBrand::query()
            ->where('user_id', $user->id)
            ->where('merchant_key', '!=', $merchantKey)
            ->where('status', MerchantBrandStatus::Resolved)
            ->where('industry', $brand->industry)
            ->when(filled($brand->subindustry), fn (Builder $query): Builder => $query->where('subindustry', $brand->subindustry))
            ->pluck('merchant_key');

        if ($neighbourKeys->isEmpty()) {
            return null;
        }

        $answers = Payee::query()
            ->where('user_id', $user->id)
            ->confirmed()
            ->whereIn('merchant_key', $neighbourKeys->all())
            ->whereNotNull('confirmed_category_id')
            ->pluck('confirmed_category_id')
            ->map(fn (mixed $id): int => (int) $id);

        if ($answers->count() < self::MIN_HINT_NEIGHBOURS) {
            return null;
        }

        $counts = $answers->countBy()->sortDesc();
        $topId = (int) $counts->keys()->first();

        if ($counts->first() / $answers->count() <= 0.5) {
            return null;
        }

        return $categories->get($topId)?->fullPath();
    }

    private function applyToUncategorised(User $user, string $merchantKey, int $categoryId): int
    {
        $applied = 0;

        $this->uncategorisedRows($user)
            ->where('merchant_key', $merchantKey)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(function (Transaction $transaction) use ($categoryId, &$applied): void {
                $transaction->category_id = $categoryId;
                $transaction->category_source = CategorySource::Suggested;
                $transaction->propagateCategoryChange = false;
                $transaction->save();
                $applied++;
            });

        return $applied;
    }

    /**
     * Visible categories, the tagged roots Jev chooses between, and each root's family
     * (the root plus every visible descendant).
     *
     * @return array{categories: Collection<int, Category>, roots: Collection<int, Category>, families: array<int, Collection<int, Category>>}
     */
    private function taxonomy(): array
    {
        $categories = Category::visibleSortedByFullPath()->keyBy('id');
        $families = [];

        foreach ($categories as $category) {
            $root = $category;

            while ($root->parent_id !== null) {
                $root = $categories->get($root->parent_id);

                if ($root === null) {
                    continue 2;
                }
            }

            if ($root->budget_tag !== null) {
                $families[$root->id] ??= collect();
                $families[$root->id]->put($category->id, $category);
            }
        }

        $roots = $categories->filter(fn (Category $category): bool => isset($families[$category->id]) && $category->parent_id === null);

        return ['categories' => $categories, 'roots' => $roots, 'families' => $families];
    }

    /**
     * Active rules that set a category: a merchant one of them matches is already decided.
     *
     * @return Collection<int, UserRule>
     */
    private function categoryRules(User $user): Collection
    {
        return UserRule::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereHas('group', fn (Builder $query): Builder => $query->where('is_active', true))
            ->get()
            ->filter(fn (UserRule $rule): bool => collect($rule->actions)->contains(
                fn (array $action): bool => ($action['type'] ?? null) === RuleActionType::SetCategory->value,
            ))
            ->values();
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return array<string, string> option id => full path
     */
    private function options(Collection $categories): array
    {
        return $categories->mapWithKeys(fn (Category $category): array => ['c'.$category->id => $category->fullPath()])->all();
    }

    private function idOf(string $optionId): int
    {
        return (int) mb_substr($optionId, 1);
    }

    /**
     * @return array<int, float>
     */
    private function probabilitiesById(TypeSafeChoiceAnswer $answer): array
    {
        $byId = [];

        foreach ($answer->probabilities as $optionId => $probability) {
            $byId[$this->idOf((string) $optionId)] = $probability;
        }

        return $byId;
    }

    private function topToSecond(TypeSafeChoiceAnswer $answer): float
    {
        $values = array_values($answer->probabilities);
        $top = (float) ($values[0] ?? 0.0);
        $second = (float) ($values[1] ?? 0.0);

        if ($second <= 0.0) {
            return $top > 0.0 ? self::MAX_TOP_TO_SECOND : 0.0;
        }

        return min($top / $second, self::MAX_TOP_TO_SECOND);
    }
}
