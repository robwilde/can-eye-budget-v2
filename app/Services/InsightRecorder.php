<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InsightPriority;
use App\Enums\InsightStatus;
use App\Enums\InsightType;
use App\Models\Account;
use App\Models\Insight;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Records generated insights and owns their read, acknowledged and expired
 * lifecycle. An insight is identified by its dedup key per user; its
 * comparison values decide whether a new run resurfaces it.
 */
final class InsightRecorder
{
    private const int TITLE_LIMIT = 254;

    /**
     * @param  array<string, mixed>  $figures
     * @param  array<string, mixed>  $comparison
     */
    public function record(
        User $user,
        InsightType $type,
        InsightPriority $priority,
        string $dedupKey,
        string $title,
        string $body,
        array $figures,
        array $comparison,
        ?Account $account = null,
        ?string $actionRoute = null,
    ): Insight {
        if ($account !== null && $account->user_id !== $user->id) {
            throw new InvalidArgumentException('An insight can only reference an account owned by its user.');
        }

        return Insight::resolveConnection()->transaction(function () use ($user, $type, $priority, $dedupKey, $title, $body, $figures, $comparison, $account, $actionRoute): Insight {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $insight = Insight::query()
                ->where('user_id', $user->id)
                ->where('dedup_key', $dedupKey)
                ->lockForUpdate()
                ->first() ?? new Insight(['user_id' => $user->id, 'dedup_key' => $dedupKey]);

            $unchanged = $insight->exists
                && $this->normalise($insight->comparison) === $this->normalise($comparison);

            $insight->title = Str::limit($title, self::TITLE_LIMIT, '…');
            $insight->figures = $figures;

            if ($unchanged && $insight->status !== InsightStatus::Expired) {
                $insight->save();

                return $insight;
            }

            $insight->fill([
                'type' => $type,
                'priority' => $priority,
                'status' => InsightStatus::Active,
                'body' => $body,
                'comparison' => $comparison,
                'account_id' => $account?->id,
                'action_route' => $actionRoute,
                'read_at' => null,
                'acknowledged_at' => null,
                'expired_at' => null,
            ])->save();

            return $insight;
        });
    }

    /**
     * @param  list<InsightType>  $ranTypes
     * @param  list<string>  $keys
     */
    public function expireMissing(User $user, array $ranTypes, array $keys): int
    {
        if ($ranTypes === []) {
            return 0;
        }

        return Insight::query()
            ->where('user_id', $user->id)
            ->whereIn('type', array_map(static fn (InsightType $type): string => $type->value, $ranTypes))
            ->whereNotIn('dedup_key', $keys)
            ->where('status', '!=', InsightStatus::Expired->value)
            ->update([
                'status' => InsightStatus::Expired->value,
                'expired_at' => now(),
            ]);
    }

    public function acknowledge(Insight $insight): void
    {
        Insight::resolveConnection()->transaction(function () use ($insight): void {
            $persisted = $this->lockPersisted($insight);

            if ($persisted->status !== InsightStatus::Expired) {
                $persisted->forceFill([
                    'status' => InsightStatus::Acknowledged,
                    'acknowledged_at' => now(),
                ])->save();
            }

            $insight->setRawAttributes($persisted->getAttributes(), true);
        });
    }

    public function undoAcknowledge(Insight $insight): void
    {
        Insight::resolveConnection()->transaction(function () use ($insight): void {
            $persisted = $this->lockPersisted($insight);

            if ($persisted->status === InsightStatus::Acknowledged) {
                $persisted->forceFill([
                    'status' => InsightStatus::Read,
                    'read_at' => $persisted->read_at ?? now(),
                    'acknowledged_at' => null,
                ])->save();
            }

            $insight->setRawAttributes($persisted->getAttributes(), true);
        });
    }

    public function markAllRead(User $user): int
    {
        return Insight::query()
            ->where('user_id', $user->id)
            ->where('status', InsightStatus::Active->value)
            ->update([
                'status' => InsightStatus::Read->value,
                'read_at' => now(),
            ]);
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function normalise(array $values): string
    {
        $sort = static function (array $items) use (&$sort): array {
            foreach ($items as $key => $item) {
                if (is_array($item)) {
                    $items[$key] = $sort($item);
                }
            }

            if (! array_is_list($items)) {
                ksort($items);
            }

            return $items;
        };

        return json_encode($sort($values), JSON_THROW_ON_ERROR);
    }

    private function lockPersisted(Insight $insight): Insight
    {
        return Insight::query()->where('user_id', $insight->user_id)->lockForUpdate()->findOrFail($insight->id);
    }
}
