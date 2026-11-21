<?php

declare(strict_types=1);

namespace App\Services\Statement;

use App\Models\Account;
use App\Models\Statement;

/**
 * Scores a statement's hints against the owner's own visible accounts. An account's
 * description and notes are never read. Below the threshold, or when the best two accounts
 * tie, there is no suggestion.
 */
final class StatementAccountMatcher
{
    public const float THRESHOLD = 0.35;

    private const float LAST_FOUR_WEIGHT = 0.4;

    private const float INSTITUTION_WEIGHT = 0.35;

    private const float NAME_WEIGHT = 0.25;

    private const float CURRENCY_WEIGHT = 0.1;

    private const int MIN_TEXT_LENGTH = 3;

    /**
     * Sets suggested_account_id and suggestion_confidence on the statement without saving it.
     */
    public function suggest(Statement $statement): void
    {
        $best = $this->best($statement);

        $statement->suggested_account_id = $best['account']->id ?? null;
        $statement->suggestion_confidence = $best === null ? null : number_format($best['confidence'], 4, '.', '');
    }

    /**
     * @return array{account: Account, confidence: float}|null
     */
    public function best(Statement $statement): ?array
    {
        $scored = [];

        $accounts = Account::query()
            ->where('user_id', $statement->user_id)
            ->visible()
            ->get(['id', 'user_id', 'name', 'institution', 'account_last4', 'currency']);

        foreach ($accounts as $account) {
            $scored[] = ['account' => $account, 'confidence' => $this->score($statement, $account)];
        }

        usort($scored, static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        $top = $scored[0] ?? null;

        if ($top === null || $top['confidence'] < self::THRESHOLD) {
            return null;
        }

        if (isset($scored[1]) && abs($scored[1]['confidence'] - $top['confidence']) < 0.00001) {
            return null;
        }

        return ['account' => $top['account'], 'confidence' => min(1.0, $top['confidence'])];
    }

    private function score(Statement $statement, Account $account): float
    {
        $score = 0.0;
        $hasText = false;

        if ($statement->last_four_hint !== null && $account->account_last4 !== null
            && $statement->last_four_hint === $account->account_last4) {
            $score += self::LAST_FOUR_WEIGHT;
            $hasText = true;
        }

        if ($this->overlaps($statement->institution_hint, $account->institution)) {
            $score += self::INSTITUTION_WEIGHT;
            $hasText = true;
        }

        if ($this->overlaps($statement->account_name_hint, $account->name)) {
            $score += self::NAME_WEIGHT;
            $hasText = true;
        }

        if ($hasText && $statement->currency !== null && $statement->currency === $account->currency) {
            $score += self::CURRENCY_WEIGHT;
        }

        return $score;
    }

    private function overlaps(?string $hint, ?string $value): bool
    {
        $hint = $this->normalise($hint);
        $value = $this->normalise($value);

        if (mb_strlen($hint) < self::MIN_TEXT_LENGTH || mb_strlen($value) < self::MIN_TEXT_LENGTH) {
            return false;
        }

        return str_contains(" {$value} ", " {$hint} ") || str_contains(" {$hint} ", " {$value} ");
    }

    private function normalise(?string $text): string
    {
        return mb_trim(mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text ?? '') ?? ''));
    }
}
