<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RuleActionType;
use Closure;

/**
 * The fields higher rules have claimed on each transaction during one rule run. Rule order is
 * priority: once a rule matching a transaction claims a field, lower rules leave that field
 * alone for the rest of the run, even when the higher rule changed nothing, for instance
 * because a person set the category. Actions that only add (append notes, tags, fee folding)
 * or that link to a plan claim nothing.
 */
final class RuleFieldClaims
{
    public const string CATEGORY = 'category';

    public const string DESCRIPTION = 'description';

    public const string CLEAN_DESCRIPTION = 'clean_description';

    public const string NOTES = 'notes';

    public const string EXCLUDED = 'excluded';

    public const string TRANSFER = 'transfer';

    /** @var array<int, array<string, true>> */
    private array $claimed = [];

    public static function fieldFor(RuleActionType $type): ?string
    {
        return match ($type) {
            RuleActionType::SetCategory => self::CATEGORY,
            RuleActionType::SetDescription => self::DESCRIPTION,
            RuleActionType::SetCleanDescription => self::CLEAN_DESCRIPTION,
            RuleActionType::SetNotes => self::NOTES,
            RuleActionType::ExcludeFromReports => self::EXCLUDED,
            RuleActionType::MarkAsTransfer => self::TRANSFER,
            RuleActionType::AppendNotes, RuleActionType::LinkToPlannedTransaction, RuleActionType::FoldIntoParent => null,
        };
    }

    public function isClaimed(int $transactionId, string $field): bool
    {
        return isset($this->claimed[$transactionId][$field]);
    }

    /**
     * The actions that do not touch a field a higher rule already claimed on this transaction.
     *
     * @param  array<int, array<string, string>>  $actions
     * @return array<int, array<string, string>>
     */
    public function unclaimed(int $transactionId, array $actions): array
    {
        return array_values(array_filter($actions, function (array $action) use ($transactionId): bool {
            $type = RuleActionType::tryFrom($action['type'] ?? '');
            $field = $type === null ? null : self::fieldFor($type);

            return $field === null || ! $this->isClaimed($transactionId, $field);
        }));
    }

    /**
     * Claim the fields these actions set. An action that cannot act on this transaction (a
     * category that is gone, a transfer target that is not the user's) claims nothing.
     *
     * @param  array<int, array<string, string>>  $actions
     * @param  (Closure(array<string, string>): bool)|null  $canAct
     */
    public function claim(int $transactionId, array $actions, ?Closure $canAct = null): void
    {
        foreach ($actions as $action) {
            $type = RuleActionType::tryFrom($action['type'] ?? '');
            $field = $type === null ? null : self::fieldFor($type);

            if ($field !== null && ($canAct === null || $canAct($action))) {
                $this->claimed[$transactionId][$field] = true;
            }
        }
    }
}
