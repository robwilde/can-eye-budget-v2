<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ApiKeyScope;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiKey>
 */
final class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tokenable_type' => (new User)->getMorphClass(),
            'tokenable_id' => fn (array $attributes): int => $attributes['user_id'],
            'name' => fake()->unique()->word(),
            'token' => hash('sha256', Str::random(40)),
            'scope' => ApiKeyScope::Read,
            'abilities' => ApiKeyScope::Read->abilities(),
            'expires_at' => null,
        ];
    }

    public function readWrite(): self
    {
        return $this->state(fn (): array => ['scope' => ApiKeyScope::ReadWrite, 'abilities' => ApiKeyScope::ReadWrite->abilities()]);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    public function revoked(): self
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }
}
