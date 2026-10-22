<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PayFrequency;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): self
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): self
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function withPayCycle(): self
    {
        return $this->state(fn (array $attributes) => [
            'pay_amount' => 300000,
            'pay_frequency' => PayFrequency::Fortnightly,
            'next_pay_date' => now()->addDays(7),
        ]);
    }

    /**
     * @param  array<string, mixed>  $preferences
     */
    public function withPreferences(array $preferences): self
    {
        return $this->state(fn (array $attributes) => [
            'preferences' => $preferences,
        ]);
    }

    public function withTimezone(string $timezone): self
    {
        return $this->state(fn (array $attributes) => [
            'timezone' => $timezone,
        ]);
    }

    public function withMonthStartDay(int $day): self
    {
        return $this->state(fn (array $attributes) => [
            'month_start_day' => $day,
        ]);
    }
}
