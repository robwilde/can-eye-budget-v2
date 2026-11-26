<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InviteCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InviteCode>
 */
final class InviteCodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = Str::random(32);

        return [
            'code_hash' => InviteCode::hashOf($code),
            'code_hint' => mb_substr($code, -4),
            'created_by_user_id' => null,
            'expires_at' => now()->addDays(7),
        ];
    }

    /**
     * A code with a known plain value, for tests that submit it.
     */
    public function withCode(string $code): self
    {
        return $this->state(fn (): array => ['code_hash' => InviteCode::hashOf($code), 'code_hint' => mb_substr($code, -4)]);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    public function revoked(): self
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }

    public function claimed(): self
    {
        return $this->state(fn (): array => ['claimed_at' => now()]);
    }
}
