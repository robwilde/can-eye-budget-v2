<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\RuleBaselineTimedOutException;
use App\Models\RuleEmailDelivery;
use App\Models\Transaction;
use App\Models\UserRule;
use Illuminate\Support\Facades\DB;

/**
 * When an email rule is switched on, every transaction it already matches is recorded as
 * delivered without sending anything, so the first digest covers only what arrives later.
 */
final readonly class RuleEmailBaseline
{
    public const int DEFAULT_TIME_LIMIT_SECONDS = 30;

    private const int CHUNK = 500;

    public function __construct(private RuleEvaluator $evaluator, private int $timeLimitSeconds = self::DEFAULT_TIME_LIMIT_SECONDS) {}

    /**
     * One database transaction: either every current match is recorded or none is.
     *
     * @return int Transactions newly recorded
     *
     * @throws RuleBaselineTimedOutException when the time limit passes before the walk finishes
     */
    public function record(UserRule $rule): int
    {
        $deadline = microtime(true) + $this->timeLimitSeconds;

        return DB::transaction(function () use ($rule, $deadline): int {
            $recorded = 0;
            $batch = [];

            Transaction::query()
                ->where('user_id', $rule->user_id)
                ->current()
                ->lazyById(self::CHUNK)
                ->each(function (Transaction $transaction) use ($rule, $deadline, &$batch, &$recorded): void {
                    if (microtime(true) > $deadline) {
                        throw new RuleBaselineTimedOutException;
                    }

                    if (! $this->evaluator->matches($transaction, $rule)) {
                        return;
                    }

                    $batch[] = $this->row($rule, $transaction->id);

                    if (count($batch) >= self::CHUNK) {
                        $recorded += RuleEmailDelivery::query()->insertOrIgnore($batch);
                        $batch = [];
                    }
                });

            if ($batch !== []) {
                $recorded += RuleEmailDelivery::query()->insertOrIgnore($batch);
            }

            return $recorded;
        });
    }

    /** @return array<string, mixed> */
    private function row(UserRule $rule, int $transactionId): array
    {
        return [
            'user_id' => $rule->user_id,
            'user_rule_id' => $rule->id,
            'transaction_id' => $transactionId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
