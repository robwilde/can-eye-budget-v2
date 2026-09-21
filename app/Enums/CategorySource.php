<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Records who chose a transaction's category.
 *
 * Without this, a retroactive rule cannot tell a category the user typed from
 * one an earlier rule guessed, so it overwrites both. Provenance is what lets
 * automation fill gaps without ever contradicting a human.
 *
 * The column is NULL exactly when category_id is NULL: an absent category has
 * no source.
 */
enum CategorySource: string
{
    /** Chosen by the user, directly or by propagation from their choice. */
    case Manual = 'manual';

    /** Written by a UserRule through RuleActionExecutor. */
    case Rule = 'rule';

    /** Supplied by the upstream bank feed's own enrichment. */
    case Feed = 'feed';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Set by you',
            self::Rule => 'Set by a rule',
            self::Feed => 'Set by your bank feed',
        };
    }

    /**
     * Whether a rule may overwrite a category carrying this provenance.
     *
     * A rule fills gaps and may correct its own earlier guesses or the feed's,
     * but never overrides a person.
     */
    public function isOverwritableByRule(): bool
    {
        return match ($this) {
            self::Manual => false,
            self::Rule, self::Feed => true,
        };
    }
}
