<?php

declare(strict_types=1);

namespace App\Services\Payees;

use App\DTOs\PayeeReviewItem;
use App\Enums\BudgetTag;
use App\Enums\MerchantBrandStatus;
use App\Enums\TransactionDirection;
use App\Models\Category;
use App\Models\MerchantBrand;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Budget\BudgetTagResolver;
use Illuminate\Support\Collection;

/**
 * The payees worth asking the user about, biggest first.
 *
 * A pending payee is worth a question when Jev was not sure (confidence or the gap to
 * the runner-up below the auto-apply line, which also covers a close call between
 * categories), when it gave no suggestion at all, or when the name could be work or
 * personal (software, online services and the big platforms). Payees Jev was sure about
 * are left alone.
 *
 * Order is monthly spend, the typical amount times how often the payee is paid per month,
 * then how many other pending payees share its brand industry and so are likely
 * to be settled by the same answer.
 */
final readonly class PayeeReviewQueue
{
    private const string AMBIGUOUS_PATTERN = '/\b(software|online|apple|microsoft|google|learning)\b/iu';

    private const int MAX_DESCRIPTIONS = 3;

    public function __construct(private BudgetTagResolver $tags) {}

    /**
     * @param  int|null  $limit  null returns every payee worth asking about
     * @return Collection<int, PayeeReviewItem>
     */
    public function pending(User $user, ?int $limit = 50): Collection
    {
        $pending = Payee::query()->where('user_id', $user->id)->pending()->orderBy('id')->get();
        $worthAsking = $pending->filter(fn (Payee $payee): bool => $this->needsReview($payee));

        if ($worthAsking->isEmpty()) {
            return collect();
        }

        $rows = Transaction::query()
            ->where('user_id', $user->id)
            ->whereIn('merchant_key', $worthAsking->pluck('merchant_key')->all())
            ->where('direction', TransactionDirection::Debit)
            ->excludingTransfers()
            ->current()
            ->whereDoesntHave('splits')
            ->orderByDesc('post_date')
            ->orderByDesc('id')
            ->get(['merchant_key', 'amount', 'description', 'post_date'])
            ->groupBy('merchant_key');

        $industryByKey = $this->industries($user);
        $pendingPerIndustry = $pending
            ->map(fn (Payee $payee): ?string => $industryByKey[$payee->merchant_key] ?? null)
            ->filter()
            ->countBy();

        $categories = Category::allWithLinkedParents()->keyBy('id');

        return $worthAsking
            ->map(function (Payee $payee) use ($user, $rows, $industryByKey, $pendingPerIndustry, $categories): array {
                $industry = $industryByKey[$payee->merchant_key] ?? null;
                $suggested = $payee->suggested_category_id === null ? null : $categories->get($payee->suggested_category_id);
                $payeeRows = $rows->get($payee->merchant_key, collect());

                return [
                    'similar' => $industry === null ? 0 : $pendingPerIndustry->get($industry, 1) - 1,
                    'item' => $this->item($payee, $payeeRows, $this->tags->forCategory($user, $suggested, $payee)),
                ];
            })
            ->sort(fn (array $a, array $b): int => [$b['item']->monthlySpend, $b['similar'], $a['item']->payeeId]
                <=> [$a['item']->monthlySpend, $a['similar'], $b['item']->payeeId])
            ->when($limit !== null, fn (Collection $entries): Collection => $entries->take($limit))
            ->map(fn (array $entry): PayeeReviewItem => $entry['item'])
            ->values();
    }

    /**
     * Whether the name could be work or personal, the question the review step asks.
     */
    public function isAmbiguous(string $merchantName): bool
    {
        return preg_match(self::AMBIGUOUS_PATTERN, $merchantName) === 1;
    }

    private function needsReview(Payee $payee): bool
    {
        return $payee->suggested_category_id === null
            || ($payee->confidence ?? 0.0) < PayeeSuggester::AUTO_APPLY_CONFIDENCE
            || ($payee->top_to_second ?? 0.0) < PayeeSuggester::AUTO_APPLY_TOP_TO_SECOND
            || $this->isAmbiguous($payee->merchant_name);
    }

    /**
     * @param  Collection<int, Transaction>  $rows  the payee's debits, newest first
     */
    private function item(Payee $payee, Collection $rows, ?BudgetTag $tag): PayeeReviewItem
    {
        $typical = $this->median($rows->map(fn (Transaction $row): int => abs((int) $row->amount))->all());

        return new PayeeReviewItem(
            payeeId: $payee->id,
            merchantName: $payee->merchant_name,
            rawDescriptions: $rows->pluck('description')->unique()->take(self::MAX_DESCRIPTIONS)->values()->all(),
            typicalAmount: $typical,
            monthlySpend: (int) round($typical * $this->paymentsPerMonth($rows)),
            suggestedCategoryId: $payee->suggested_category_id,
            suggestedTag: $tag,
            isAmbiguous: $this->isAmbiguous($payee->merchant_name),
            transactionCount: $rows->count(),
        );
    }

    /**
     * @param  Collection<int, Transaction>  $rows
     */
    private function paymentsPerMonth(Collection $rows): float
    {
        if ($rows->isEmpty()) {
            return 0.0;
        }

        $first = $rows->min('post_date');
        $last = $rows->max('post_date');
        $months = ($last->year - $first->year) * 12 + $last->month - $first->month + 1;

        return $rows->count() / $months;
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$middle]
            : intdiv($values[$middle - 1] + $values[$middle], 2);
    }

    /**
     * @return array<string, string> merchant key => industry (and subindustry) of its resolved brand
     */
    private function industries(User $user): array
    {
        return MerchantBrand::query()
            ->where('user_id', $user->id)
            ->where('status', MerchantBrandStatus::Resolved)
            ->whereNotNull('industry')
            ->get(['merchant_key', 'industry', 'subindustry'])
            ->mapWithKeys(fn (MerchantBrand $brand): array => [$brand->merchant_key => $brand->industry.'|'.$brand->subindustry])
            ->all();
    }
}
