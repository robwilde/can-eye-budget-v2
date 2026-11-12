<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

/**
 * The fields a bulk edit changes. A field the user did not include is null (or, for notes,
 * not set), and the row keeps its value: only what is named here is written.
 */
final class BulkTransactionEdit extends Dto
{
    /**
     * @param  CarbonImmutable|null  $date  the new post date, or null to leave dates alone
     * @param  string|null  $name  the new name, or null to leave names alone
     * @param  bool  $setNotes  whether notes are included; false leaves them alone
     * @param  string|null  $notes  the new notes when included, where null clears them
     * @param  list<int>|null  $tagIds  the tags to set, where an empty list clears them and null leaves tags alone
     */
    public function __construct(
        public readonly ?CarbonImmutable $date = null,
        public readonly ?string $name = null,
        public readonly bool $setNotes = false,
        public readonly ?string $notes = null,
        public readonly ?array $tagIds = null,
    ) {}

    public function changesOnlyDate(): bool
    {
        return $this->date !== null && $this->name === null && ! $this->setNotes && $this->tagIds === null;
    }

    public function changesOnlyTags(): bool
    {
        return $this->tagIds !== null && $this->date === null && $this->name === null && ! $this->setNotes;
    }
}
