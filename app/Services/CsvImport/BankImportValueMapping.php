<?php

declare(strict_types=1);

namespace App\Services\CsvImport;

use App\Models\BankImport;
use App\Models\Category;
use App\Models\Tag;
use App\Models\Transaction;
use App\Services\FeedCategoryMatcher;

/**
 * The user's choices for each distinct category and tag value in a file: a category value maps
 * to one of their categories or to nothing; a tag value maps to one of their tags, to a new tag
 * of that name, or to nothing. Nothing imports until every choice points at something the user owns.
 */
final readonly class BankImportValueMapping
{
    public const string TAG_EXISTING = 'existing';

    public const string TAG_NEW = 'new';

    private const int MAX_TAG_NAME = 50;

    public function __construct(private FeedCategoryMatcher $categories) {}

    /**
     * A stable key for a value in the file, safe to put in a page.
     */
    public static function key(string $value): string
    {
        return mb_substr(hash('sha256', $value), 0, 16);
    }

    /**
     * The tag names in one cell: separated by commas or pipes.
     *
     * @return list<string>
     */
    public static function tagNames(?string $cell): array
    {
        $names = [];

        foreach (preg_split('/[,|]/u', (string) $cell) ?: [] as $name) {
            $name = mb_trim($name);

            if ($name !== '') {
                $names[mb_strtolower($name)] ??= $name;
            }
        }

        return array_values($names);
    }

    /**
     * @return list<string>
     */
    public function categoryValues(BankImport $import): array
    {
        return $import->rows()->whereNotNull('category_value')->pluck('category_value')->unique()->sort()->values()->all();
    }

    /**
     * @return list<string>
     */
    public function tagValues(BankImport $import): array
    {
        $names = [];

        foreach ($import->rows()->whereNotNull('tag_value')->pluck('tag_value')->unique() as $cell) {
            foreach (self::tagNames($cell) as $name) {
                $names[mb_strtolower($name)] ??= $name;
            }
        }

        ksort($names);

        return array_values($names);
    }

    /**
     * Starting choices: a category that matches by name, a tag that already exists, otherwise nothing.
     *
     * @return array{categories: array<string, int|null>, tags: array<string, array{type: string, id?: int}|null>}
     */
    public function suggest(BankImport $import): array
    {
        $categories = [];

        foreach ($this->categoryValues($import) as $value) {
            $categories[$value] = $this->categories->match($import->user_id, $value);
        }

        $tags = [];
        $existing = Tag::query()->where('user_id', $import->user_id)->pluck('id', 'name')->mapWithKeys(fn (int $id, string $name): array => [mb_strtolower($name) => $id]);

        foreach ($this->tagValues($import) as $name) {
            $id = $existing->get(mb_strtolower($name));
            $tags[$name] = $id === null ? null : ['type' => self::TAG_EXISTING, 'id' => $id];
        }

        return ['categories' => $categories, 'tags' => $tags];
    }

    /**
     * What is wrong with the saved choices: a category or tag that is not the user's.
     *
     * @return list<string>
     */
    public function problems(BankImport $import): array
    {
        $mapping = $import->value_mapping ?? ['categories' => [], 'tags' => []];
        $problems = [];

        $categoryIds = array_values(array_filter($mapping['categories'] ?? [], fn (mixed $id): bool => $id !== null));
        $ownedCategories = Category::query()->where('user_id', $import->user_id)->whereIn('id', $categoryIds)->count();

        if ($ownedCategories !== count(array_unique($categoryIds))) {
            $problems[] = __('A category you chose is no longer available.');
        }

        $tagIds = [];

        foreach ($mapping['tags'] ?? [] as $choice) {
            if ($choice === null) {
                continue;
            }

            if ($choice['type'] === self::TAG_EXISTING && isset($choice['id'])) {
                $tagIds[] = $choice['id'];
            } elseif ($choice['type'] !== self::TAG_NEW) {
                $problems[] = __('A tag choice is not valid.');
            }
        }

        foreach ($mapping['tags'] ?? [] as $name => $choice) {
            if (($choice['type'] ?? null) === self::TAG_NEW && (mb_strlen((string) $name) > self::MAX_TAG_NAME || mb_strtolower((string) $name) === Tag::RESERVED_NAME)) {
                $problems[] = __('A new tag name is too long or reserved: :name', ['name' => mb_substr((string) $name, 0, 50)]);
            }
        }

        if (Tag::query()->where('user_id', $import->user_id)->whereIn('id', $tagIds)->count() !== count(array_unique($tagIds))) {
            $problems[] = __('A tag you chose is no longer available.');
        }

        return array_values(array_unique($problems));
    }

    public function categoryFor(BankImport $import, ?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $id = $import->value_mapping['categories'][$value] ?? null;

        return $id !== null && Category::query()->where('user_id', $import->user_id)->whereKey($id)->exists() ? (int) $id : null;
    }

    /**
     * The tag choice for each tag name, by lower-cased name: the user's saved choices, or, when
     * the import was never reviewed, their existing tags of the same name.
     *
     * @return array<string, array{type: string, id?: int}|null>
     */
    public function tagChoices(BankImport $import): array
    {
        $saved = $import->value_mapping['tags'] ?? $this->suggest($import)['tags'];
        $choices = [];

        foreach ($saved as $name => $choice) {
            $choices[mb_strtolower((string) $name)] = $choice;
        }

        return $choices;
    }

    /**
     * Attaches the mapped tags to a transaction, creating a tag the user chose to create.
     *
     * @param  array<string, array{type: string, id?: int}|null>  $choices  from tagChoices()
     */
    public function attachTags(BankImport $import, Transaction $transaction, ?string $cell, array $choices): void
    {
        $ids = [];

        foreach (self::tagNames($cell) as $name) {
            $choice = $choices[mb_strtolower($name)] ?? null;

            if ($choice === null) {
                continue;
            }

            $ids[] = $choice['type'] === self::TAG_NEW
                ? $this->tagNamed($import->user_id, $name)->id
                : Tag::query()->where('user_id', $import->user_id)->whereKey($choice['id'] ?? 0)->value('id');
        }

        $ids = array_values(array_filter($ids));

        if ($ids !== []) {
            $transaction->tags()->syncWithoutDetaching($ids);
        }
    }

    private function tagNamed(int $userId, string $name): Tag
    {
        return Tag::query()->where('user_id', $userId)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? Tag::query()->create(['user_id' => $userId, 'name' => $name]);
    }
}
