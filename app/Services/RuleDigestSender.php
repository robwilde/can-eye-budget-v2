<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RuleActionType;
use App\Models\RuleEmailDelivery;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Notifications\RuleMatchDigest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Emails a user one digest per active email rule, covering only the matching transactions that
 * rule has not delivered before. The delivery rows and the send share one database transaction
 * under a lock on the rule, so a failed send leaves nothing recorded and a concurrent run cannot
 * email the same transaction twice.
 */
final readonly class RuleDigestSender
{
    public function __construct(private RuleEvaluator $evaluator) {}

    /**
     * @return int Digests sent
     */
    public function send(User $user): int
    {
        $sent = 0;

        foreach ($this->emailRules($user) as $rule) {
            $sent += $this->sendFor($user, $rule) ? 1 : 0;
        }

        return $sent;
    }

    /** @return Collection<int, UserRule> */
    private function emailRules(User $user): Collection
    {
        return UserRule::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereHas('group', fn ($group) => $group->where('is_active', true))
            ->ordered()
            ->get()
            ->filter(static fn (UserRule $rule): bool => array_any($rule->actions, static fn (array $action): bool => ($action['type'] ?? null) === RuleActionType::EmailDigest->value))
            ->values();
    }

    private function sendFor(User $user, UserRule $rule): bool
    {
        return DB::transaction(function () use ($user, $rule): bool {
            $locked = UserRule::query()->where('user_id', $user->id)->whereKey($rule->id)->lockForUpdate()->first();

            if (! $locked instanceof UserRule || ! $locked->is_active) {
                return false;
            }

            $delivered = RuleEmailDelivery::query()
                ->where('user_id', $user->id)
                ->where('user_rule_id', $locked->id)
                ->pluck('transaction_id')
                ->flip();

            $matches = Transaction::query()
                ->where('user_id', $user->id)
                ->current()
                ->with('account')
                ->orderBy('post_date')
                ->orderBy('id')
                ->lazyById(500)
                ->filter(fn (Transaction $transaction): bool => ! $delivered->has($transaction->id) && $this->evaluator->matches($transaction, $locked))
                ->values();

            if ($matches->isEmpty()) {
                return false;
            }

            RuleEmailDelivery::query()->insert($matches->map(static fn (Transaction $transaction): array => [
                'user_id' => $user->id,
                'user_rule_id' => $locked->id,
                'transaction_id' => $transaction->id,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());

            $user->notify(new RuleMatchDigest($locked, $matches->all()));

            return true;
        });
    }
}
