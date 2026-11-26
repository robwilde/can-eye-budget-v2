<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\InstanceSettingValue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One instance-level setting, by key. The value of a secret (a provider API key) is encrypted at rest and hidden from
 * serialization; nothing here is per user.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class InstanceSetting extends Model
{
    public const string REGISTRATION_MODE = 'registration_mode';

    public const string CONTEXT_DEV_API_KEY = 'context_dev_api_key';

    public const string OPENAI_API_KEY = 'openai_api_key';

    public const string ANTHROPIC_API_KEY = 'anthropic_api_key';

    public const string LLM_PROVIDER = 'llm_provider';

    public const string LLM_MODEL = 'llm_model';

    public const string LLM_ENDPOINT = 'llm_endpoint';

    public const string LLM_TOKEN_BUDGET = 'llm_token_budget';

    public const string ASSISTANT_MODE = 'assistant_mode';

    public const string SYSTEM_PROMPT = 'system_prompt';

    public const string INCLUDE_PENDING = 'include_pending';

    public const string AUTO_SYNC = 'auto_sync';

    public const string AUTO_SYNC_TIME = 'auto_sync_time';

    /** @var list<string> */
    public const array SECRET_KEYS = [
        self::CONTEXT_DEV_API_KEY,
        self::OPENAI_API_KEY,
        self::ANTHROPIC_API_KEY,
    ];

    /** @var list<string> */
    protected $fillable = ['key', 'value'];

    /** @var list<string> */
    protected $hidden = ['value'];

    /**
     * Stores a value, or removes the setting when it is null or empty so the environment config applies again.
     */
    public static function put(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            self::query()->where('key', $key)->delete();

            return;
        }

        $setting = self::query()->where('key', $key)->first() ?? new self(['key' => $key]);
        $setting->value = $value;
        $setting->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => InstanceSettingValue::class];
    }
}
