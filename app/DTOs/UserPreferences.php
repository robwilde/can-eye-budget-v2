<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\SupportedLocale;
use App\Enums\Theme;
use App\Enums\TransactionPeriod;
use BackedEnum;
use Spatie\LaravelData\Dto;

final class UserPreferences extends Dto
{
    public function __construct(
        public readonly Theme $theme = Theme::System,
        public readonly ?SupportedLocale $locale = null,
        public readonly TransactionPeriod $defaultPeriod = TransactionPeriod::ThisMonth,
        public readonly bool $rulePromptEnabled = true,
        public readonly bool $previewFeatures = false,
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
            previewFeatures: is_bool($stored['previewFeatures'] ?? null) ? $stored['previewFeatures'] : $defaults->previewFeatures,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_map(
            static fn (mixed $value): mixed => $value instanceof BackedEnum ? $value->value : $value,
            get_object_vars($this),
        );
    }
}
