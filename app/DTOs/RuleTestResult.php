<?php

declare(strict_types=1);

namespace App\DTOs;

use Livewire\Wireable;
use Spatie\LaravelData\Dto;

/**
 * What a draft rule matches in the user's current history and what Apply now
 * would do to each match, computed without saving anything.
 *
 * Counts cover every match; each row list is capped to the newest rows.
 *
 * Wireable so UserRuleManager can hold it between requests: computing it walks
 * the user's whole history, so it is recomputed only when the person asks.
 */
final class RuleTestResult extends Dto implements Wireable
{
    /**
     * @param  bool  $appendsNotes  an append_notes action adds its note again on every run
     * @param  list<RuleTestRow>  $notYetApplied
     * @param  list<RuleTestRow>  $skipped
     * @param  list<RuleTestRow>  $alreadyApplied
     */
    public function __construct(
        public readonly int $notYetAppliedCount,
        public readonly int $skippedCount,
        public readonly int $alreadyAppliedCount,
        public readonly bool $appendsNotes,
        public readonly array $notYetApplied = [],
        public readonly array $skipped = [],
        public readonly array $alreadyApplied = [],
    ) {}

    /** @param  array<string, mixed>  $value */
    public static function fromLivewire($value): self
    {
        return new self(
            notYetAppliedCount: (int) $value['notYetAppliedCount'],
            skippedCount: (int) $value['skippedCount'],
            alreadyAppliedCount: (int) $value['alreadyAppliedCount'],
            appendsNotes: (bool) $value['appendsNotes'],
            notYetApplied: self::rowsFromLivewire($value['notYetApplied']),
            skipped: self::rowsFromLivewire($value['skipped']),
            alreadyApplied: self::rowsFromLivewire($value['alreadyApplied']),
        );
    }

    public function totalMatches(): int
    {
        return $this->notYetAppliedCount + $this->skippedCount + $this->alreadyAppliedCount;
    }

    /** @return array<string, mixed> */
    public function toLivewire(): array
    {
        return [
            'notYetAppliedCount' => $this->notYetAppliedCount,
            'skippedCount' => $this->skippedCount,
            'alreadyAppliedCount' => $this->alreadyAppliedCount,
            'appendsNotes' => $this->appendsNotes,
            'notYetApplied' => array_map(static fn (RuleTestRow $row): array => $row->toLivewire(), $this->notYetApplied),
            'skipped' => array_map(static fn (RuleTestRow $row): array => $row->toLivewire(), $this->skipped),
            'alreadyApplied' => array_map(static fn (RuleTestRow $row): array => $row->toLivewire(), $this->alreadyApplied),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<RuleTestRow>
     */
    private static function rowsFromLivewire(array $rows): array
    {
        return array_values(array_map(RuleTestRow::fromLivewire(...), $rows));
    }
}
