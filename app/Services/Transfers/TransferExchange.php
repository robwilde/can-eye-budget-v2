<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Models\Transaction;
use App\Models\User;
use App\Services\ExchangeRateLookup;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Currency maths for transfers between accounts in different currencies: the rate for a
 * user (through their base currency when neither side is it), the converted amount, and
 * whether two opposite legs in different currencies are close enough to be one transfer.
 */
final class TransferExchange
{
    public const float MAX_TOLERANCE_PERCENT = 50.0;

    /** @var array<string, float|null> */
    private array $rates = [];

    /** @var array<int, string> */
    private array $bases = [];

    private bool $memoise = false;

    public function __construct(private readonly ExchangeRateLookup $lookup) {}

    /**
     * The configured tolerance in percent, kept between 0 and MAX_TOLERANCE_PERCENT.
     */
    public function tolerancePercent(): float
    {
        $configured = (float) config('budget.transfer_fx_tolerance', 10);

        return max(0.0, min(self::MAX_TOLERANCE_PERCENT, $configured));
    }

    /**
     * A copy that remembers the rates and base currencies it looks up. The container's instance
     * is held by long-lived services (the analysis pipeline is a singleton), so it keeps nothing
     * between calls; a caller comparing many rows takes one of these for the length of its run.
     */
    public function memoising(): self
    {
        $copy = new self($this->lookup);
        $copy->memoise = true;

        return $copy;
    }

    /**
     * A date after today is looked up as today: a rate cannot be quoted for a day that has not
     * happened, and the form's preview does the same.
     */
    public function rate(int $userId, string $from, string $to, CarbonInterface $date): ?float
    {
        $today = CarbonImmutable::today();
        $date = $date->greaterThan($today) ? $today : $date;

        if (! $this->memoise) {
            return $this->lookup->rateViaBase($from, $to, $this->baseCurrency($userId), $date);
        }

        $key = implode('|', [$userId, mb_strtoupper($from), mb_strtoupper($to), $date->toDateString()]);

        if (! array_key_exists($key, $this->rates)) {
            $this->rates[$key] = $this->lookup->rateViaBase($from, $to, $this->baseCurrency($userId), $date);
        }

        return $this->rates[$key];
    }

    public function convert(int $cents, float $rate): int
    {
        return (int) round($cents * $rate);
    }

    /**
     * What an account in $to receives when $cents leave an account in $from: the same amount for
     * one currency, else converted at $customRate or the rate for $date, rounded to whole cents.
     * Null when the currencies differ and no usable rate exists.
     */
    public function incoming(int $userId, int $cents, string $from, string $to, CarbonInterface $date, ?float $customRate = null): ?int
    {
        if (mb_strtoupper($from) === mb_strtoupper($to)) {
            return $cents;
        }

        $rate = $customRate ?? $this->rate($userId, $from, $to, $date);
        $converted = $rate === null ? 0 : $this->convert($cents, $rate);

        return $converted > 0 ? $converted : null;
    }

    /**
     * Whether $debit and $credit, in different currencies, are within the tolerance of each
     * other once the debit is converted at the rate for its date. False when no rate exists.
     */
    public function qualifies(Transaction $debit, Transaction $credit): bool
    {
        if ($debit->currency === $credit->currency || $debit->user_id !== $credit->user_id) {
            return false;
        }

        $rate = $this->rate($debit->user_id, $debit->currency, $credit->currency, $debit->post_date);

        if ($rate === null) {
            return false;
        }

        $converted = $this->convert(abs($debit->amount), $rate);

        return $converted > 0
            && abs(abs($credit->amount) - $converted) * 100 <= $this->tolerancePercent() * $converted;
    }

    private function baseCurrency(int $userId): string
    {
        if (! $this->memoise) {
            return (string) User::query()->whereKey($userId)->value('base_currency');
        }

        return $this->bases[$userId] ??= (string) User::query()->whereKey($userId)->value('base_currency');
    }
}
