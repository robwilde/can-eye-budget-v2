<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Account;
use App\Models\RedbarkAccount;
use App\Models\RedbarkFeed;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RedbarkAccount>
 */
final class RedbarkAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $institution = fake()->randomElement(['Commonwealth Bank', 'Westpac', 'ANZ', 'NAB', 'ING']);
        $name = fake()->randomElement(['Everyday Account', 'Smart Access', 'Complete Freedom', 'Orange Everyday']);

        return [
            'redbark_feed_id' => RedbarkFeed::factory(),
            'account_id' => null,
            'redbark_account_id' => 'rb_acc_'.fake()->unique()->regexify('[a-z0-9]{12}'),
            'bank_connection_id' => 'rb_conn_'.fake()->regexify('[a-z0-9]{8}'),
            'name' => "$institution - $name",
            'account_number' => '****'.fake()->numberBetween(1000, 9999),
            'currency' => 'AUD',
            'current_balance' => fake()->numberBetween(10000, 500000),
            'account_type' => 'transaction',
            'institution_name' => $institution,
            'ignored' => false,
            'sync_start_date' => null,
            'transactions_synced_at' => null,
            'raw_transactions_payload' => null,
        ];
    }

    public function linked(?Account $account = null): self
    {
        return $this->state(fn (array $attributes): array => [
            'account_id' => $account instanceof Account ? $account->id : Account::factory(),
        ]);
    }

    public function ignored(): self
    {
        return $this->state(fn (array $attributes): array => [
            'ignored' => true,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function withSnapshot(array $rows): self
    {
        return $this->state(fn (array $attributes): array => [
            'raw_transactions_payload' => $rows,
        ]);
    }
}
