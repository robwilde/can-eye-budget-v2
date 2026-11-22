<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AccountOrder;
use App\Enums\SupportedLocale;
use App\Enums\Theme;
use App\Enums\TransactionPeriod;
use App\Support\Currencies;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Spatie\LaravelData\Dto;
use Throwable;

final class UserPreferences extends Dto
{
    public const int RECENT_CATEGORY_LIMIT = 4;

    /**
     * @param  list<string>  $allowedCurrencies  Currencies the pickers offer; empty means every supported currency.
     * @param  list<int>  $recentCategoryIds  Categories the user last picked by hand, most recent first.
     */
    public function __construct(
        public readonly Theme $theme = Theme::System,
        public readonly ?SupportedLocale $locale = null,
        public readonly TransactionPeriod $defaultPeriod = TransactionPeriod::ThisMonth,
        public readonly bool $rulePromptEnabled = true,
        public readonly ?CarbonImmutable $rulePromptHiddenUntil = null,
        public readonly AccountOrder $accountOrder = AccountOrder::NameAsc,
        public readonly bool $previewFeatures = false,
        public readonly array $allowedCurrencies = [],
        public readonly bool $categoriesLocked = false,
        public readonly array $recentCategoryIds = [],
        public readonly bool $autoSyncOnLogin = false,
    ) {}

    /**
     * Build from persisted JSON, falling back to the default for any key whose stored value is no longer valid.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromStored(array $stored): self
    {
        $defaults = new self;

        return new self(
            theme: is_string($stored['theme'] ?? null) ? Theme::tryFrom($stored['theme']) ?? $defaults->theme : $defaults->theme,
            locale: is_string($stored['locale'] ?? null) ? SupportedLocale::tryFrom($stored['locale']) : $defaults->locale,
            defaultPeriod: is_string($stored['defaultPeriod'] ?? null) ? TransactionPeriod::tryFrom($stored['defaultPeriod']) ?? $defaults->defaultPeriod : $defaults->defaultPeriod,
            rulePromptEnabled: is_bool($stored['rulePromptEnabled'] ?? null) ? $stored['rulePromptEnabled'] : $defaults->rulePromptEnabled,
            rulePromptHiddenUntil: self::moment($stored['rulePromptHiddenUntil'] ?? null),
            accountOrder: is_string($stored['accountOrder'] ?? null) ? AccountOrder::tryFrom($stored['accountOrder']) ?? $defaults->accountOrder : $defaults->accountOrder,
            previewFeatures: is_bool($stored['previewFeatures'] ?? null) ? $stored['previewFeatures'] : $defaults->previewFeatures,
            allowedCurrencies: is_array($stored['allowedCurrencies'] ?? null) ? Currencies::normaliseList($stored['allowedCurrencies']) : $defaults->allowedCurrencies,
            categoriesLocked: is_bool($stored['categoriesLocked'] ?? null) ? $stored['categoriesLocked'] : $defaults->categoriesLocked,
            recentCategoryIds: is_array($stored['recentCategoryIds'] ?? null) ? self::categoryIds($stored['recentCategoryIds']) : $defaults->recentCategoryIds,
            autoSyncOnLogin: is_bool($stored['autoSyncOnLogin'] ?? null) ? $stored['autoSyncOnLogin'] : $defaults->autoSyncOnLogin,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_map(
            static fn (mixed $value): mixed => match (true) {
                $value instanceof BackedEnum => $value->value,
                $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->toIso8601String(),
                default => $value,
            },
            get_object_vars($this),
        );
    }

    private static function moment(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return list<int>
     */
    private static function categoryIds(array $ids): array
    {
        $valid = array_filter($ids, static fn (mixed $id): bool => (is_int($id) && $id > 0) || (is_string($id) && ctype_digit($id) && (int) $id > 0));

        return array_slice(array_values(array_unique(array_map(intval(...), $valid))), 0, self::RECENT_CATEGORY_LIMIT);
    }
}
